<?php

/*
Plugin Name: EISRF Importer
Description: Импорт записей с сайтов, созданных компанией "Центр информационных технологий и систем"
Version: 1.0
Author: Михаил Карасев
Author URI: https://github.com/michaelwright235
License: GNU General Public License v2.0
*/

require_once ABSPATH . 'wp-admin/includes/import.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

if ( !class_exists( 'WP_Importer' ) ) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-importer.php');
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

require_once plugin_dir_path( __FILE__ ) . '/eisrf-funcs.php';
require_once plugin_dir_path( __FILE__ ) . '/class-authorize.php';
require_once plugin_dir_path( __FILE__ ) . '/class-sitelist.php';
require_once plugin_dir_path( __FILE__ ) . '/class-news-provider.php';
require_once plugin_dir_path( __FILE__ ) . '/class-pages-provider.php';
require_once plugin_dir_path( __FILE__ ) . '/class-post-importer.php';

// Показ ошибок при работе с AJAX
/*if( WP_DEBUG && WP_DEBUG_DISPLAY && (defined('DOING_AJAX') && DOING_AJAX) )
	@ ini_set( 'display_errors', 1 );
*/

class EISRF_Importer extends WP_Importer {

    public function init() {
        $this->header();
        $this->greet();
        $this->footer();
        return true;
    }

    public function header() {
        echo '<div class="wrap">';
        echo '<h2>Импорт записей с портала eisrf.ru</h2>';
        return true;
    }

    public function footer() {
        echo '</div>';
        return true;
    }

    public function greet() {
        $nonce = wp_create_nonce( 'eisrf_form_nonce' );
        ?>
<div class="narrow">
	<p>Добро пожаловать в плагин импорта записей с портала eisrf.ru. Данный плагин позволить вам перенести все имеющиеся новости, а также изображения, прикрепленные к ним.</p>
	<div id="tabs">
		<ul>
			<li id="p0"><a href="#tab0" id="tab0_link">Вход</a></li>
            <li id="p1"><a href="#tab1" id="tab1_link">Выбор сайта</a></li>
			<li id="p2"><a href="#tab2" id="tab2_link">Выбор новостей</a></li>
            <li id="p3"><a href="#tab3" id="tab3_link">Выбор опций</a></li>
			<li id="p4"><a href="#tab4" id="tab4_link">Импорт</a></li>
		</ul>

        <!-- ВКЛАДКА: ВХОД -->
        <div id="tab0">
            <form id="eisrf_authorize_form">
                <table>
                <tr>
                    <td><label for="eisrf_login">Логин:</label></td>
                    <td><input id="eisrf_login" type="text" name="eisrf_login" size="30"/></td>
                </tr>
                <tr>
                    <td><label for="eisrf_password">Пароль:</label></td>
                    <td><input id="eisrf_password" type="password" name="eisrf_password" size="30"/></td>
                </table>
                <input type="hidden" name="action" value="eisrf_authorize" />
                <input type="hidden" name="nonce_code" value="<?php echo $nonce;?>" id="eisrf_nonce"/>
                <p><input type="submit" class="button button-primary" value="Войти"/></p>
                <div id="eisrf_authorize_log"></div>
            </form>
        </div>

        <!-- ВКЛАДКА: ВЫБОР САЙТА -->
        <div id="tab1">
            <form id="eisrf_choose_site">
                <p>Список доступных сайтов:</p>
                <div id="available_sites" style="display: none"></div>
                <input type="submit" class="button button-primary" id="eisrf_choose_site_button" style="display: none;" value="Далее"/>
            </form>
        </div>

        <!-- ВКЛАДКА: ВЫБОР НОВОСТЕЙ -->
        <div id="tab2">
            <div id="news_or_pages">
                <label><input type="radio" value="news" name="choose_news_or_pages" checked="checked">Новости</label>
                <label style="padding-left: 40px;"><input type="radio" value="pages" name="choose_news_or_pages">Страницы</label>
            </div>
            <div id="eisrf_posts_table"></div>
            <div id="eisrf_pages_table" style="display: none"></div>
            <input type="button" class="button button-primary" id="eisrf_choose_posts_button" style="display: none;" value="Далее"/>
        </div>

        <!-- ВКЛАДКА: ВЫБОР ОПЦИЙ -->
        <div id="tab3">
            <form id="eisrf_import_posts">
			<label for="eisrf_category_id">Выберите рубрику для импортируемых записей:<br/>
				<?php
				wp_dropdown_categories( array(
					'hide_empty'       => 0,
					'name'             => 'eisrf_category_id',
					'orderby'          => 'name',
					'hierarchical'     => 1
				) );
				?>
			</label><br/>
			<label><input type="checkbox" name="eisrf_import_as_pages" id="eisrf_import_as_pages" value="on"/>Импортировать как страницы</label><br/>
			<label><input type="checkbox" name="eisrf_remove_empty_p" checked="checked" value="on"/>Удалять пустые абзацы</label><br/><br/>
			<label><input type="checkbox" name="eisrf_download_images" id="eisrf_download_images" checked="checked" value="on"/>Загружать изображения</label><br/>
            <label><input type="checkbox" name="eisrf_put_images_in_gallery" id="eisrf_put_images_in_gallery" checked="checked" value="on"/>Помещать все изображения в галерею под записью</label><br/>
			<label>Выберите размер изображений в записи:<br/>
			<select name="eisrf_image_size" id="eisrf_image_size" disabled="disabled">
				<option value="eisrf_the_same">Оставить оригинальный размер</option>
				<?php
				$sizes = eisrf_get_image_sizes();
				foreach ($sizes as $size => $pixels) {
					$selected='';
					if($size==EISRF_Post_Importer::$DEFAULT_IMG_SIZE) $selected='selected="selected"'; // ставим размер по-умолчанию
					echo "\t\t\t\t<option value=\"$size\" $selected>$size ($pixels[width]x$pixels[height])</option>\n";
				}
				?>
			</select></label><br/>
            <label for="eisrf_image_alignment">Выберите выравнивание изображений в записи:</label>
                <p class="description">Указание значений &quot;По левому краю&quot; и &quot;По правому краю&quot; может привести к разрушению макета записи</p>
                <select name="eisrf_image_alignment" id="eisrf_image_alignment" disabled="disabled">
                    <option value="eisrf_image_alignment_none">Без выравнивания</option>
                    <option value="eisrf_image_alignment_left">По левому краю</option>
                    <option value="eisrf_image_alignment_center" selected="selected">По центру</option>
                    <option value="eisrf_image_alignment_right">По правому краю</option>
                </select><br/><br/>
			<input type="hidden" name="action" value="eisrf_import_post" />
            <input type="hidden" name="nonce_code" value="<?php echo $nonce;?>" />
            <input type="submit" class="button button-primary" value="Начать импорт"/>
            </form>
        </div>

        <!-- ВКЛАДКА ВЫБОР ИМПОРТ -->
        <div id="tab4">
            <!-- EMPTY -->
        </div>
	</div>
</div>
        <?php
        return true;
    }
}

// Регистрация импортера
$eisrf_importer = new EISRF_Importer();
register_importer('eisrf-importer',
    'Импорт из EISRF',
    'Импорт записей с сайтов, созданных компанией "Центр информационных технологий и систем"',
    array ($eisrf_importer, 'init'));

// Добавление стилей и скриптов на страницу
add_action( 'admin_enqueue_scripts', 'eisrf_enqueue_script');
function eisrf_enqueue_script() {
    // Защита от добавления скриптов/стилей на ненужные страницы админки
    if(!isset($_GET['import'])) return;
	if($_GET['import'] != 'eisrf-importer') return; // Адрес получается вида "admin.php?import=eisrf-importer"

    wp_enqueue_script('jquery-ui-core');
    wp_enqueue_script('jquery-ui-tabs');
    wp_enqueue_script('jquery-effects-fade');
	wp_enqueue_style('eisrf-jquery-ui-css-1', plugins_url('/includes/jquery-ui.min.css',__FILE__));
	wp_enqueue_style('eisrf-jquery-ui-css-2', plugins_url('/includes/jquery-ui.theme.min.css',__FILE__));
    wp_enqueue_style('eisrf-jquery-ui-css-3', plugins_url('/includes/jquery-ui.structure.min.css',__FILE__));

    wp_enqueue_script('eisrf-script', plugins_url('/includes/eisrf-script.js',__FILE__));
    wp_enqueue_style('eisrf-style', plugins_url('/includes/eisrf-style.css',__FILE__));
}

