document.addEventListener('DOMContentLoaded', function () {
    var masked = document.querySelectorAll('input[data-credential-mask]');

    masked.forEach(function (input) {
        if (input.dataset.credentialManaged === '1') {
            return;
        }

        if (input.readOnly) {
            var roForm = input.closest('form');

            if (roForm) {
                roForm.addEventListener('submit', function () {
                    if (input.dataset.credentialMask === '1') {
                        input.value = '';
                    }
                });
            }

            return;
        }

        input.addEventListener('focus', function () {
            if (input.dataset.credentialMask !== '1') {
                return;
            }

            setTimeout(function () {
                if (input.dataset.credentialMask === '1') {
                    input.select();
                }
            }, 0);
        });

        input.addEventListener('beforeinput', function () {
            if (input.dataset.credentialMask === '1') {
                input.value = '';
                input.dataset.credentialMask = '0';
            }
        });

        var form = input.closest('form');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function () {
            if (input.dataset.credentialMask === '1') {
                input.value = '';
            }
        });
    });
});
