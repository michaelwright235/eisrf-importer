<?php

class EISRF_Authorizer {
    const LOGIN_URL = 'https://lk.eisrf.ru/user/login.ajax';
    const COOKIE_NAME = 'tu';

    public function __construct() {
        $login = $_POST['eisrf_login'];
        $password = $_POST['eisrf_password'];

        // Получение JSON о количестве новостей
        $requestArgs = array(
            'headers' => array(
                'accept'=> 'application/json, text/javascript, *//*',
                'x-requested-with' => 'XMLHttpRequest'
            ),
            'method'=>'POST',
            'body' => array('__login'=>$login,'__password'=>$password)
        );
        $request = wp_remote_request(self::LOGIN_URL,$requestArgs);
        if (is_wp_error($request)) {
            $status = array('status'=>'-1', 'text'=>$request->get_error_message());
            echo json_encode($status);
            return false;
        }

        if(isset($request['headers']['set-cookie'])) {
            preg_match_all("|".self::COOKIE_NAME."\=([^;]*)|",$request['headers']['set-cookie'],$cookie);
            $cookie = array(
                'status'=>'0',
                'cookie'=> array(self::COOKIE_NAME=>$cookie[1][0])
            );
        }
        else
            $cookie = array(
                'status'=>'-1',
                'text'=>'Неверный логин или пароль');

        echo json_encode($cookie);
        return true;
    }
}

function eisrfAuthorize() {
    check_ajax_referer( 'eisrf_form_nonce', 'nonce_code' );
    new EISRF_Authorizer();
    wp_die();
}

if( wp_doing_ajax() ){
    add_action( 'wp_ajax_eisrf_authorize', 'eisrfAuthorize' );
}