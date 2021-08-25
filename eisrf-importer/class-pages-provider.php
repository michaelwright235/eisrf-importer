<?php

class EISRF_Pages_Provider extends WP_List_Table {

    const BASE_URL_PAGES = 'https://lk.eisrf.ru/user/cp/%s/site_pages';

    public $total_count;
    private $cookie;
    private $site_id;

    public function __construct() {
        $this->site_id = $_POST['eisrf_site_id'];
        $this->cookie = $_POST['eisrf_cookie'];

       parent::__construct( [
            'singular' => 'Статья', //singular name of the listed records
            'plural'   => 'Статьи', //plural name of the listed records
            'ajax'     => true //should this table support ajax?
        ] );
    }

    private function get_pages() {
        $posts = array();

        // Получение JSON о количестве новостей
        $request_args = array(
            'headers' => array(
                'accept'=> 'application/json, text/javascript, *//*',
                'x-requested-with' => 'XMLHttpRequest'
            ),
            'cookies'=> $this->cookie,
            'method'=>'GET'
        );

        $request_link = sprintf(self::BASE_URL_PAGES, $this->site_id);
        $request = wp_remote_request($request_link,$request_args);
        if (is_wp_error($request)) {
            return false;
        }

        // Обработка запроса
        $json = json_decode($request['body']);
        if(!$json) return false;

        $this->total_count = $json->pager->total;

        foreach ($json->rows as $cell_obj) {
            $element = array();
            $element['ID'] = $cell_obj->id;
            foreach ($cell_obj->cells as $single_cell) {
                switch($single_cell->fld) {
                    case 'title':
                        $element['post_title'] = $single_cell->val;
                        break;
                    case 'create_time':
                        $element['post_date'] = $single_cell->val;
                        break;
                }
            }
            array_push($posts,$element);
        }
        return $posts;
    }

    public function prepare_items() {

        $this->_column_headers = $this->get_column_info();

        $this->items  = $this->get_pages();
        $per_page     = $this->total_count; // В JSON не идет разделение на страницы
        $total_items  = $this->total_count;

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page
        ] );
    }

    function get_columns() {
        $columns = [
            'cb'      => '<input type="checkbox" />',
            'post_title'    => 'Название',
            'post_date'     => 'Дата'
        ];

        return $columns;
    }

    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'post_title':
            case 'post_date':
                return $item[ $column_name ];
            default:
                return print_r( $item, true ); //Show the whole array for troubleshooting purposes
        }
    }

    public function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="eisrf-pages[]" value="%s" />', $item['ID']
        );
    }

    public function column_name( $item ) {

        $title = '<strong>' . $item['name'] . '</strong>';

        return $title;
    }

    public function no_items() {
        echo 'Нет элементов';
    }
}

function eisrfGetPages() {
    check_ajax_referer( 'eisrf_form_nonce', 'nonce_code' );
    $wp_list_table = @new EISRF_Pages_Provider(); // todo исправить проблему с hook_suffix
    $wp_list_table->prepare_items();
    $wp_list_table->display();
    wp_die();
}

if( wp_doing_ajax() ) {
    add_action( 'wp_ajax_eisrf_get_pages', 'eisrfGetPages' );
}