(function () {
	'use strict';

	function live(id, text) {
		var el = document.getElementById(id);
		if (el) {
			el.textContent = text;
		}
	}

	window.LogCheckToasts = {
		showSuccess: function (message) {
			live('lck-live-region', message);
			if (window.OC && OC.Notification && OC.Notification.showTemporary) {
				OC.Notification.showTemporary(message);
			}
		},
		showError: function (message) {
			live('lck-alert-region', message);
			if (window.OC && OC.Notification && OC.Notification.showTemporary) {
				OC.Notification.showTemporary(message, { type: 'error' });
			}
		}
	};
})();
