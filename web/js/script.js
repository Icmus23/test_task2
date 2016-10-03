$(document).ready(function() {
    $('.recent_logs').click(function() {
        $('#login_form_login').val($(this).html());
    });

    $('#login_form_password').keyup(function() {
        var login = $('#login_form_login').val();
        var pass = $(this).val();

        if (pass.trim() != '' && login.trim() != '') {
            $.post('/get-browser-statistics/', $('form[name="login_form"]').serialize(), function(response) {
                var browser_log = $('#browser_log');
                if (response.length == 0) {
                    browser_log.html('Здесь будет появляться история входов в текущий браузер всех пользователей');
                    browser_log.css('color', 'gray');
                } else {
                    browser_log.html('История входов пользователей с данного браузера:');
                    $.each(response, function(key, value) {
                        browser_log.append("<p>" + value.login + " | " + value.time.date + "</p>");
                    });
                    browser_log.css('color', 'black');
                }
            }, "json");
        }
    });
});