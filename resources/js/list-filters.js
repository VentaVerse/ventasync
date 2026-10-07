document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () {
            if (select.form) select.form.submit();
        });
    });
});
