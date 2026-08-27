<?php

declare(strict_types=1);

namespace OCA\LogCheck\Tests\Unit\Service;

use OCA\LogCheck\Service\Channel\EmailChannel;
use OCA\LogCheck\Service\Channel\NotificationChannel;
use OCA\LogCheck\Service\Channel\SlackChannel;
use OCA\LogCheck\Service\Channel\WebhookChannel;
use OCA\LogCheck\Service\ChannelDispatcher;
use OCA\LogCheck\Service\ChannelStateStore;
use OCA\LogCheck\Service\DeliveryStore;
use OCA\LogCheck\Service\LeaseService;
use OCA\LogCheck\Service\PendingStore;
use OCA\LogCheck\Service\SecretStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ChannelDispatcherTest extends TestCase
{
	/** @var ChannelStateStore&MockObject */
	private ChannelStateStore $state;
	/** @var PendingStore&MockObject */
	private PendingStore $pending;
	/** @var DeliveryStore&MockObject */
	private DeliveryStore $delivery;
	/** @var NotificationChannel&MockObject */
	private NotificationChannel $notification;
	/** @var EmailChannel&MockObject */
	private EmailChannel $email;
	/** @var LeaseService&MockObject */
	private LeaseService $lease;
	private ChannelDispatcher $dispatcher;

	protected function setUp(): void
	{
		$this->email = $this->createMock(EmailChannel::class);
		$slack = $this->createMock(SlackChannel::class);
		$webhook = $this->createMock(WebhookChannel::class);
		$this->notification = $this->createMock(NotificationChannel::class);
		$secrets = $this->createMock(SecretStore::class);
		$this->state = $this->createMock(ChannelStateStore::class);
		$this->pending = $this->createMock(PendingStore::class);
		$this->delivery = $this->createMock(DeliveryStore::class);
		$this->lease = $this->createMock(LeaseService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->dispatcher = new ChannelDispatcher(
			$this->email,
			$slack,
			$webhook,
			$this->notification,
			$secrets,
			$this->state,
			$this->pending,
			$this->delivery,
			$this->lease,
			$logger,
		);
	}

	public function testDisabledPendingIsAbandoned(): void
	{
		$this->pending->expects(self::exactly(2))->method('claimOne')
			->willReturnOnConsecutiveCalls(
				[
					'event_id' => 'evt-1',
					'channel' => 'email',
					'payload' => ['total_matched' => 1],
					'attempts' => 0,
					'status' => 'sending',
					'created_at' => time(),
					'updated_at' => 1001,
					'claim_gen' => 1001,
				],
				null
			);
		$this->state->method('isDisabled')->with('email')->willReturn(true);

		$this->pending->expects(self::once())->method('markAbandoned')
			->with('evt-1', 'email', 'channel_disabled');
		$this->delivery->expects(self::once())->method('record')
			->with('evt-1', 'email', 'abandoned');
		$this->email->expects(self::never())->method('send');
		$this->notification->expects(self::never())->method('notifyChannelDisabled');

		$this->dispatcher->dispatchPending([
			'channels' => [
				'email' => ['enabled' => true, 'recipients' => ['a@b.c']],
			],
		]);
	}

	public function testFifthFailureNotifiesChannelDisabled(): void
	{
		$this->pending->expects(self::exactly(2))->method('claimOne')
			->willReturnOnConsecutiveCalls(
				[
					'event_id' => 'evt-2',
					'channel' => 'email',
					'payload' => ['total_matched' => 2],
					'attempts' => 4,
					'status' => 'sending',
					'created_at' => time(),
					'updated_at' => 2002,
					'claim_gen' => 2002,
				],
				null
			);
		$this->state->method('isDisabled')->willReturn(false);
		$this->email->method('send')->willThrowException(new \RuntimeException('smtp down'));
		$this->state->expects(self::once())->method('recordFailure')
			->with('email', 'smtp down')
			->willReturn(true);

		$settings = [
			'channels' => [
				'email' => ['enabled' => true, 'recipients' => ['a@b.c']],
				'notification' => ['enabled' => true, 'recipient_uids' => []],
			],
		];
		$this->notification->expects(self::once())->method('notifyChannelDisabled')
			->with('email', $settings);
		$this->pending->expects(self::once())->method('markFailed')
			->with('evt-2', 'email', 5, 2002);

		$this->dispatcher->dispatchPending($settings);
	}

	public function testFailureBelowThresholdDoesNotNotify(): void
	{
		$this->pending->expects(self::exactly(2))->method('claimOne')
			->willReturnOnConsecutiveCalls(
				[
					'event_id' => 'evt-3',
					'channel' => 'email',
					'payload' => ['total_matched' => 1],
					'attempts' => 1,
					'status' => 'sending',
					'created_at' => time(),
					'updated_at' => 3003,
					'claim_gen' => 3003,
				],
				null
			);
		$this->state->method('isDisabled')->willReturn(false);
		$this->email->method('send')->willThrowException(new \RuntimeException('temp'));
		$this->state->method('recordFailure')->willReturn(false);
		$this->notification->expects(self::never())->method('notifyChannelDisabled');
		$this->pending->expects(self::once())->method('markFailed')
			->with('evt-3', 'email', 2, 3003);

		$this->dispatcher->dispatchPending([
			'channels' => [
				'email' => ['enabled' => true, 'recipients' => ['a@b.c']],
			],
		]);
	}

	public function testLeaseLossReturnsClaimedRowToPending(): void
	{
		$this->pending->expects(self::once())->method('claimOne')->willReturn([
			'event_id' => 'evt-4',
			'channel' => 'email',
			'payload' => ['total_matched' => 1],
			'attempts' => 0,
			'status' => 'sending',
			'created_at' => time(),
			'updated_at' => 4004,
			'claim_gen' => 4004,
		]);
		$this->lease->method('renew')->with('owner-1')->willReturn(false);
		$this->pending->expects(self::once())->method('markFailed')->with('evt-4', 'email', 0, 4004);
		$this->email->expects(self::never())->method('send');

		$this->dispatcher->dispatchPending([
			'channels' => ['email' => ['enabled' => true, 'recipients' => ['a@b.c']]],
		], 'owner-1');
	}

	public function testLostMarkSentDoesNotRecordDeliverySuccess(): void
	{
		$this->pending->expects(self::exactly(2))->method('claimOne')
			->willReturnOnConsecutiveCalls(
				[
					'event_id' => 'evt-5',
					'channel' => 'email',
					'payload' => ['total_matched' => 1],
					'attempts' => 0,
					'status' => 'sending',
					'created_at' => time(),
					'updated_at' => 5005,
					'claim_gen' => 5005,
				],
				null
			);
		$this->state->method('isDisabled')->willReturn(false);
		$this->delivery->method('hasSent')->willReturn(false);
		$this->email->expects(self::once())->method('send');
		$this->pending->expects(self::once())->method('markSent')
			->with('evt-5', 'email', 5005)
			->willReturn(false);
		$this->delivery->expects(self::never())->method('record');
		$this->state->expects(self::never())->method('recordSuccess');

		$this->dispatcher->dispatchPending([
			'channels' => [
				'email' => ['enabled' => true, 'recipients' => ['a@b.c']],
			],
		]);
	}

	/** After a prior successful delivery, reclaim must not HTTP again. */
	public function testAlreadySentSkipsOutboundAndCompletesPending(): void
	{
		$this->pending->expects(self::exactly(2))->method('claimOne')
			->willReturnOnConsecutiveCalls(
				[
					'event_id' => 'evt-6',
					'channel' => 'email',
					'payload' => ['total_matched' => 1],
					'attempts' => 1,
					'status' => 'sending',
					'created_at' => time(),
					'updated_at' => 6006,
					'claim_gen' => 6006,
				],
				null
			);
		$this->state->method('isDisabled')->willReturn(false);
		$this->delivery->expects(self::once())->method('hasSent')
			->with('evt-6', 'email')
			->willReturn(true);
		$this->email->expects(self::never())->method('send');
		$this->pending->expects(self::once())->method('markSent')
			->with('evt-6', 'email', 6006)
			->willReturn(true);
		$this->delivery->expects(self::never())->method('record');
		$this->state->expects(self::never())->method('recordSuccess');

		$this->dispatcher->dispatchPending([
			'channels' => [
				'email' => ['enabled' => true, 'recipients' => ['a@b.c']],
			],
		]);
	}
}
