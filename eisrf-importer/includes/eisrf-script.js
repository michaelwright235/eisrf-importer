var eisrf_cookie;
var site_id;
var request_timeout = 0;
var loading_html = '<div class="eisrf-loading" style="display: none;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" role="img" aria-hidden="true" focusable="false"><path class="outer" d="M48 12c19.9 0 36 16.1 36 36S67.9 84 48 84 12 67.9 12 48s16.1-36 36-36" fill="none"></path><path class="inner" d="M69.5 46.4c0-3.9-1.4-6.7-2.6-8.8-1.6-2.6-3.1-4.9-3.1-7.5 0-2.9 2.2-5.7 5.4-5.7h.4C63.9 19.2 56.4 16 48 16c-11.2 0-21 5.7-26.7 14.4h2.1c3.3 0 8.5-.4 8.5-.4 1.7-.1 1.9 2.4.2 2.6 0 0-1.7.2-3.7.3L40 67.5l7-20.9L42 33c-1.7-.1-3.3-.3-3.3-.3-1.7-.1-1.5-2.7.2-2.6 0 0 5.3.4 8.4.4 3.3 0 8.5-.4 8.5-.4 1.7-.1 1.9 2.4.2 2.6 0 0-1.7.2-3.7.3l11.5 34.3 3.3-10.4c1.6-4.5 2.4-7.8 2.4-10.5zM16.1 48c0 12.6 7.3 23.5 18 28.7L18.8 35c-1.7 4-2.7 8.4-2.7 13zm32.5 2.8L39 78.6c2.9.8 5.9 1.3 9 1.3 3.7 0 7.3-.6 10.6-1.8-.1-.1-.2-.3-.2-.4l-9.8-26.9zM76.2 36c0 3.2-.6 6.9-2.4 11.4L64 75.6c9.5-5.5 15.9-15.8 15.9-27.6 0-5.5-1.4-10.8-3.9-15.3.1 1 .2 2.1.2 3.3z" fill="none"></path></svg><p id="eisrf-loading-text">Загрузка...</p></div>';

jQuery(function ($) {

$("#eisrf_download_images").click(function () {
    if ($("#eisrf_download_images").is(":checked")) {
        $("#eisrf_put_images_in_gallery").attr('disabled', false);
        if(!$("#eisrf_put_images_in_gallery").is(":checked")) {
            $("#eisrf_image_size").attr('disabled', false);
            $("#eisrf_image_alignment").attr('disabled', false);
        }
    } else {
        $("#eisrf_put_images_in_gallery").attr('disabled', true);
        $("#eisrf_image_size").attr('disabled', true);
        $("#eisrf_image_alignment").attr('disabled', true);
    }
});

$("#eisrf_put_images_in_gallery").click(function () {
    if($("#eisrf_put_images_in_gallery").is(":checked")) {
        $("#eisrf_image_size").attr('disabled', true);
        $("#eisrf_image_alignment").attr('disabled', true);
    }
    else {
        $("#eisrf_image_size").attr('disabled', false);
        $("#eisrf_image_alignment").attr('disabled', false);
    }
});

$("#eisrf_import_as_pages").click(function () {
    if($("#eisrf_import_as_pages").is(":checked")) {
        $("#eisrf_category_id").attr('disabled', true);
    }
    else {
        $("#eisrf_category_id").attr('disabled', false);
    }
});

/* Настройка вкладок Jquery UI */
var Etabs = $( "#tabs" ).tabs({
    show: { effect: "fade", duration: 200 }
});
Etabs.tabs( "option", "disabled", [ 1, 2, 3, 4 ] );
Etabs.addClass( "ui-tabs-vertical ui-helper-clearfix" );
$( "#tabs li" ).removeClass( "ui-corner-top" ).addClass( "ui-corner-left" );

// Делаем так, чтобы нажатие на всю область кнопки открывало вкладку
$("#p0").click(function() {Etabs.tabs("option", "active", 0);});
$("#p1").click(function() {Etabs.tabs("option", "active", 1);});
$("#p2").click(function() {Etabs.tabs("option", "active", 2);});
$("#p3").click(function() {Etabs.tabs("option", "active", 3);});
$("#p4").click(function() {Etabs.tabs("option", "active", 4);});

function eisrf_loading_show() {
    $(".ui-tabs-nav").append(loading_html);
    $(".eisrf-loading").fadeIn(200);
}
function eisrf_loading_hide() {
    $(".eisrf-loading").fadeOut(200).remove();
}

/**  TAB_0: ВХОД  **/
$("#eisrf_authorize_form").submit(function( event ) {
    event.preventDefault();
    eisrf_loading_show();
    tab0_authorize();
});

function tab0_authorize() {
    $.ajax({
        url:      ajaxurl,
        type:     "POST",
        data: $("#eisrf_authorize_form").serialize(),
        success: function(response) {
            var obj = JSON.parse(response);
            if(obj.status==='0') {
                eisrf_cookie = obj.cookie;
                Etabs.tabs("enable", 1 );
                Etabs.tabs("option", "active", 1);
                Etabs.tabs("disable", 0);
                tab1_sitelist();
            } else {
                $("#eisrf_authorize_log").html('<p class="eisrf-red">Ошибка: '+obj.text+'</p>');
                eisrf_loading_hide();
            }
        },
        error: function(response) {
            $("#eisrf_authorize_log").html('<p class="eisrf-red">'+'Ошибка. Данные не отправлены. <pre>'+response+'</pre>');
            eisrf_loading_hide();
        },
        complete: function () {
            eisrf_loading_hide();
        }
    });
}

/**  TAB_1: СПИСОК САЙТОВ  **/
function tab1_sitelist() {
    $.ajax({
        url:      ajaxurl,
        type:     "POST",
        data: {
            'action': 'eisrf_sitelist',
            'eisrf_cookie': eisrf_cookie,
            'nonce_code': $('#eisrf_nonce').val()
        },
        success: function(response) {
            var obj = JSON.parse(response);
            if(obj.status==='0') {
                var sites = obj.sites;
                var isFirst = true;
                $.each(sites, function(index, value) {
                    var checked;
                    if(isFirst) {
                        checked = 'checked="checked"';
                        isFirst = false;
                    }
                    $("#available_sites").append('<p><label><input name="eisrf_siteId" type="radio" value="'+value[0]+'" '+checked+'>'+value[1]+'</label></p></li>')
                        .fadeIn(200);
                });
                $("#eisrf_choose_site_button").fadeIn(200);
            } else {
                $("#available_sites").html('<p class="eisrf-red">Ошибка: '+obj.text+'</p>').fadeIn(200);
            }
        },
        error: function(response) {
            $("#available_sites").html('<p class="eisrf-red">'+'Ошибка. Данные не отправлены. <pre>'+response+'</pre>').fadeIn(200);
        },
        complete: function () {
            eisrf_loading_hide();
        }
    });
}

/**  TAB_2: СПИСОК НОВОСТЕЙ  **/
$("#eisrf_choose_site").submit(function( event ) {
    event.preventDefault();
    Etabs.tabs("enable", 2 );
    Etabs.tabs("option", "active", 2);
    Etabs.tabs("disable", 1);
    site_id = $('input[name=eisrf_siteId]', '#eisrf_choose_site').val();
    tab2_news_table(1);
});

$('input[name=choose_news_or_pages]:radio').change(function() {
        if(this.value === 'news') {
            $('#eisrf_posts_table').toggle();
            $('#eisrf_pages_table').toggle();
        }
        if(this.value === 'pages') {
            $('#eisrf_posts_table').toggle();
            $('#eisrf_pages_table').toggle();
            if($('#eisrf_pages').length===0) {
                $('#eisrf_choose_posts_button').hide();
                tab2_pages_table();
            }
        }
});

function tab2_news_table(paged) {
    paged = parseInt(paged);

    var current_el = $('#eisrf-page-'+paged);
    if( current_el.length!==0 ) {
        $('#eisrf_posts_table>div:visible').hide();
        current_el.fadeIn(200);
        return;
    }
    eisrf_loading_show();

    $.ajax({
        url:      ajaxurl,
        type:     "POST",
        data:     {
            'action': 'eisrf_get_news',
            'paged': paged || '1',
            'eisrf_site_id': site_id,
            'eisrf_cookie': eisrf_cookie,
            'nonce_code': $('#eisrf_nonce').val()
        },
        success: function(response) {
                $('#eisrf_posts_table>div:visible').hide();
                $("#eisrf_posts_table").append('<div id="eisrf-page-'+paged+'" style="display: none">'+response+'</div>');
                $('#eisrf-page-'+paged).fadeIn(200);
                bind_table(paged);
                if(paged === 1) $("#eisrf_choose_posts_button").fadeIn(200);
        },
        error: function() { // Данные не отправлены
            $("#tab2").append('Ошибка. Данные не отправлены.<br/>');
        },
        complete: function () {
            eisrf_loading_hide();
        }
    });
}

function bind_table(paged) {
    $(function() {
        $('#eisrf-page-'+paged+' .tablenav-pages a').on('click', function(e) {
            // We don't want to actually follow these links
            e.preventDefault();
            var paged = __query( this.search.substring( 1 ), 'paged' ); // Simple way: use the URL to extract our needed variables
            if(!paged) paged = 1; // для кнопки '<<' на последней странице таблицы
            tab2_news_table(paged);
        });
    });
}
function __query( query, variable ) {

    var vars = query.split("&");
    for ( var i = 0; i <vars.length; i++ ) {
        var pair = vars[ i ].split("=");
        if ( pair[0] === variable )
            return pair[1];
    }
    return false;
}

function tab2_pages_table() {
    eisrf_loading_show();

    $.ajax({
        url:      ajaxurl,
        type:     "POST",
        data:     {
            'action': 'eisrf_get_pages',
            'eisrf_site_id': site_id,
            'eisrf_cookie': eisrf_cookie,
            'nonce_code': $('#eisrf_nonce').val()
        },
        success: function(response) {
            $("#eisrf_pages_table").append('<div id="eisrf_pages" style="display: none">'+response+'</div>');
            $("#eisrf_pages").fadeIn(200);
            $('#eisrf_choose_posts_button').fadeIn(200);
        },
        error: function() { // Данные не отправлены
            $("#tab2").append('Ошибка. Данные не отправлены.<br/>');
        },
        complete: function () {
            eisrf_loading_hide();
        }
    });
}



/**  TAB_3: ВЫБОР ОПЦИЙ  **/
$("#eisrf_choose_posts_button").click(function (e) {
    e.preventDefault();
    if($("input[name='eisrf-posts[]']:checked").length===0 && $("input[name='eisrf-pages[]']:checked").length===0 ) {
        alert('Вы не выбрали ни одной записи');
        return;
    }
    Etabs.tabs("enable", 3 );
    Etabs.tabs("option", "active", 3);
});

$("#eisrf_import_posts").submit(function (event) {
    event.preventDefault();
    Etabs.tabs("enable", 4 );
    Etabs.tabs("option", "active", 4);
    eisrf_loading_show();
    tab3_import_posts();
});

/* Отправка на сервер */
function tab3_import_posts() {
    var posts_ids = [];
    $("input[name='eisrf-posts[]']:checked").each(function() {
        posts_ids.push($(this).val());
    });
    import_single_post(posts_ids, 0);
}

function import_single_post(posts_ids, counter) {
    if(counter === posts_ids.length) {
        // После импорта всех записей импортируем страницы
        var pages_ids = [];
        $("input[name='eisrf-pages[]']:checked").each(function() {
            pages_ids.push($(this).val());
        });

        if(pages_ids.length!==0)
            import_single_page(pages_ids, 0);
        else
            eisrf_loading_hide();

        return;
    }
    $("#eisrf-loading-text").html('Загрузка записи</br>'+(counter+1)+' из '+posts_ids.length+'...');
    $.ajax({
        url:      ajaxurl,
        type:     "POST",
        data: $("#eisrf_import_posts").serialize()+'&'+
            $.param(({'eisrf_cookie':eisrf_cookie}))+'&'+
            $.param({'eisrf_post_id':posts_ids[counter]})+'&'+
            $.param({'eisrf_site_id':site_id})+'&'+
            $.param({'eisrf_is_page':false}),
        success: function(response) {
            var obj;
            try {
                obj = JSON.parse(response);
            } catch (e) {
                $("#tab4").append('<p>Ошибка - неправильный ответ</p>'+'<pre>'+response+'</pre>');
                eisrf_loading_hide();
            }

            var successful = 0;
            $.each(obj, function(index, value) {
                if(value['code'] === 1) {
                    $("#tab4").append('<p class="eisrf-green">'+value['message']+'</p>');
                }
                if(value['code'] === -1) {
                    $("#tab4").append('<p class="eisrf-red">'+value['message']+'</p>');
                    successful = value['code'];
                }
            });
            var yellow = '<ul>';
            $.each(obj, function(index, value) {
                if(value['code'] === 0) {
                    yellow += '<li class="eisrf-yellow">'+value['message']+'</li>';
                }
            });
            if(yellow !== '<ul>') $("#tab4").append(yellow+'</ul>');
            if(successful !== -1) {
                $("#eisrf-loading-text").html('Перерыв</br>'+request_timeout+' сек.');
                setTimeout(import_single_post, request_timeout*1000, posts_ids, counter+1);
            } else {
                import_single_post(posts_ids, counter+1);
            }

        },
        error: function() { // Данные не отправлены
            $("#tab4").append('<p>Ошибка. Данные не отправлены. Повтор через 10 сек.</p>');
            setTimeout(import_single_post, 10*1000, posts_ids, counter);
        }
    });
}

function import_single_page(pages_ids, counter) {
    if(counter === pages_ids.length) {
        eisrf_loading_hide();
        return;
    }

    $("#eisrf-loading-text").html('Загрузка страницы</br>'+(counter+1)+' из '+pages_ids.length+'...');
    $.ajax({
        url:      ajaxurl,
        type:     "POST",
        data: $("#eisrf_import_posts").serialize()+'&'+
            $.param(({'eisrf_cookie':eisrf_cookie}))+'&'+
            $.param({'eisrf_post_id':pages_ids[counter]})+'&'+
            $.param({'eisrf_site_id':site_id})+'&'+
            $.param({'eisrf_is_page':'true'}),
        success: function(response) {
            var obj;
            try {
                obj = JSON.parse(response);
            } catch (e) {
                $("#tab4").append('<p>Ошибка - неправильный ответ</p>'+'<pre>'+response+'</pre>');
                eisrf_loading_hide();
            }

            var successful = 0;
            $.each(obj, function(index, value) {
                if(value['code'] === 1) {
                    $("#tab4").append('<p class="eisrf-green">'+value['message']+'</p>');
                }
                if(value['code'] === -1) {
                    $("#tab4").append('<p class="eisrf-red">'+value['message']+'</p>');
                    successful = value['code'];
                }
            });
            var yellow = '<ul>';
            $.each(obj, function(index, value) {
                if(value['code'] === 0) {
                    yellow += '<li class="eisrf-yellow">'+value['message']+'</li>';
                }
            });
            if(yellow !== '<ul>') $("#tab4").append(yellow+'</ul>');
            if(successful !== -1) {
                $("#eisrf-loading-text").html('Перерыв</br>'+request_timeout+' сек.');
                setTimeout(import_single_page, request_timeout*1000, pages_ids, counter+1);
            } else {
                import_single_page(pages_ids, counter+1);
            }

        },
        error: function() { // Данные не отправлены
            $("#tab4").append('<p>Ошибка. Данные не отправлены. Повтор через 10 сек.</p>');
            setTimeout(import_single_page, 10*1000, pages_ids, counter);
        }
    });
}


});