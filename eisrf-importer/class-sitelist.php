<?php

class EISRF_Importer_SiteList {
    const USER_URL = 'https://lk.eisrf.ru/user/';
    const LOOK_FOR = 'user/cp/';

    public function __construct() {
        $cookie = $_POST['eisrf_cookie'];

        $requestArgs = array(
            'cookies'=> $cookie,
            'method'=>'GET'
        );
        $request = wp_remote_request(self::USER_URL,$requestArgs);

        if (is_wp_error($request)) {
            $status = array('status'=>'-1', 'text'=>$request->get_error_message());
            echo json_encode($status);
            return false;
        }

        $doc = new DomDocument();
        @$doc->loadHTML($request['body']);
        $xpath = new DomXPath($doc);
        $sites = array();

        // В меню слева отображаются все доступные сайты
        $query = '//ul[@id="side-menu"]/li/a';
        $links = $xpath->query($query);

        for($i = 0; $i<$links->length; $i++) {
            $href = $links->item($i)->getAttribute('href');
            $pos = strpos($href, self::LOOK_FOR);
            if($pos !== false) {
                array_push($sites, array(
                    0 => substr($href,$pos+strlen(self::LOOK_FOR)),
                    1 => $links->item($i)->nodeValue
                ));
            }
        }
        if(count($sites)==0) {
            $status = array('status'=>'-1', 'text'=>'Нет сайтов, прикрепленных к аккаунту');
            echo json_encode($status);
            return false;
        }

        $status = array('status'=>'0', 'sites'=>$sites);
        echo json_encode($status);
        return true;
    }
}

function eisrfSiteList() {
    check_ajax_referer( 'eisrf_form_nonce', 'nonce_code' );
    new EISRF_Importer_SiteList();
    wp_die();
}

if( wp_doing_ajax() ){
    add_action( 'wp_ajax_eisrf_sitelist', 'eisrfSiteList');
}