$(function () {

    $(":file").filestyle({
        btnClass: "btn-primary",
        text: "Оберіть файл",
        buttonName: "btn-primary",
        iconName: "fa fa-folder-open"
    });

    $(document).on("submit", "form", function (e) {

        e.preventDefault();
        var form = $(this);
        console.log($(form).attr("action"));
        $.ajax({
            url: $(form).attr("action"),
            type: 'POST',
            cache: false,
            contentType: false,
            processData: false,
            data: new FormData(this),
            dataType: 'json'
        }).done(function (data) {
            console.log(data);
            var html = '<dl>';
            $.each(JSON.parse(data.tx_info.content.body.description), function (index, value) {

                if(index === 'declarant'){
                    html += '<dt>' + index + '</dt>' + '<dd>' + value.last_name + ' ' + value.first_name + '' + value.middle_name + '</dd>';
                }
                else {
                    html += '<dt>' + index + '</dt>' + '<dd>' + value + '</dd>';
                }
            });
             html += '</dl>';
            $("#result").html(html);
        }).fail(function ( jqXHR, textStatus, errorThrown ) {
            console.log(jqXHR);
            console.log(textStatus);
            console.log(errorThrown);
        });
    });
});