<?php

class EISRF_Post_Importer {

    static $POST_AUTHOR = 1;
    static $POST_STATUS = 'publish';
    static $DEFAULT_IMG_SIZE = 'medium';
    static $POST_URL = 'https://lk.eisrf.ru/user/cp/%s/news/%s/edit';
    static $PAGE_URL = 'https://lk.eisrf.ru/user/cp/%s/site_pages/%s/edit';
    static $ALBUM_URL = 'https://lk.eisrf.ru/user/cp/%s/albums/getObjects/OrgSiteAlbumImage?query={"album":%s}';
    static $HOST_FOR_IMAGES = 'https://lk.eisrf.ru/';
    static $HOST_FOR_ALBUM_IMAGES = 'https://lk.eisrf.ru/media/';
    static $STYLES_TO_KEEP = array('width','height', array('text-align','center'), array('text-align','right'));
    static $EMPTY_TAGS_TO_DELETE = array('p','span','div','h1','h2','h3','h4');
    static $MAX_IMAGES_IN_GALLERY_ROW = 3; // todo сделать настраиваевым
    static $GALLERY_HTML = '<!-- wp:gallery {"ids":[%s],"linkTo":"media"} -->'.
                           '<ul class="wp-block-gallery columns-%s is-cropped">%s'.
                           '</ul>'.
                           '<!-- /wp:gallery -->';
    static $GALLERY_IMAGE_HTML = '<li class="blocks-gallery-item"><figure><a href="%s">'.
                                 '<img src="%s" alt="%s" data-id="%s" class="wp-image-%s" />'.
                                 '</a></figure></li>';
    private $siteID;
    private $postID;
    private $cookie;
    private $isPage;
    private $post = array();
    private $postThumbnailID;
    private $postImages = array();
    private $postGalleryImages = array();
    private $imageHashes = array();
    private $status = array();
    private $timeForImages;
    private $whiteListOfStyles;
    private $startTime;

    private $categoryID = array();
    private $downloadImages;
    private $importAsPages;
    private $removeEmptyParas;
    private $putImagesInGallery;
    private $imageSize;
    private $imageAlignment;

    public function __construct() {
        $this->startTime = microtime(true);
        $this->getParams();
        $this->processPage();
        $this->importPost();
        echo json_encode($this->status);
    }

    ###
    ### Функция, отвечающая за полный разбор страницы
    ###
    private function processPage() {

        // Загрузка страницы из админки
        if(!$this->isPage)
        $url = sprintf(self::$POST_URL, $this->siteID, $this->postID);
        else $url = sprintf(self::$PAGE_URL, $this->siteID, $this->postID);

        $requestArgs = array(
            'cookies'=> $this->cookie,
            'method'=>'GET'
        );
        $request = wp_remote_request($url, $requestArgs);
        if (is_wp_error($request)) {
            $this->death($request->get_error_message());
        }

        //Поиск JSON объекта (заключен в JavaScript на странице)
        $pattern = '|qwx\.edit2\.initQwxEditable\((.*)\)|m';
        preg_match_all($pattern,$request['body'],$jsonMatch);
        $json = json_decode($jsonMatch[1][0]);

        // Заносим уже известные данные в массив
        $this->post['post_author']	= self::$POST_AUTHOR;
        $this->post['post_status']	= self::$POST_STATUS;
        $this->post['post_type'] = ($this->importAsPages)?'page':'post';
        if(!$this->importAsPages) $this->post['post_category'] = $this->categoryID;
        $this->post['post_date'] = (!$this->isPage) ? $json->date->value : date('Y-m-d H:i:s');
        $this->post['post_excerpt'] = (!$this->isPage) ? self::cleanQuotesAndSpaces($json->annotation->value) : '';
        $this->post['post_title'] = self::cleanQuotesAndSpaces($json->title->value);

        $this->timeForImages = strtotime($this->post['post_date']);

        // Проверка на существование такого же поста на самом сайте по названию и по дате
        $postExists = post_exists($this->post['post_title'],null,$this->post['post_date']);
        if ($postExists) {
            $this->death('Запись уже импортирована');
        }

        // Работа с телом статьи
        $html = $json->text->value;
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'utf-8');
        $html = self::cleanQuotesAndSpaces($html);

        $doc = new DomDocument();
        @$doc->loadHTML($html, LIBXML_HTML_NODEFDTD ); //LIBXML_HTML_NOIMPLIED - не работает
        $xpath = new DomXPath($doc);
        $elements = $xpath->query('.//*');

        $imagesHtml = '';
        $imagesCounter = 0;
        $this->whiteListOfStyles = self::createWhiteListOfStyles();

        for($i = 0; $i < $elements->length; $i++) {
            $el = $elements->item($i);

            // Удаление пустых абзацев
            if ($this->removeEmptyParas) {
                if(in_array(strtolower($el->tagName),self::$EMPTY_TAGS_TO_DELETE) &&
                    trim(self::cleanQuotesAndSpaces($el->textContent))=='' && $el->childNodes->length == 0 ) {
                    $el->parentNode->removeChild($el);
                    continue;
                }
            }

            //Работа со стилями
            $el->removeAttribute('data-mce-style');
            $el->removeAttribute('class');
            $style = $el->getAttribute('style');
            if($style) {
                $style = self::keepSelectedStyles($style, $this->whiteListOfStyles);
                if($style) $el->setAttribute('style', $style);
                else $el->removeAttribute('style'); // если после удаления ненужных стилей тэг оказался пустым - удаляем его
            }

            // Находим все картинки, загружаем их и заменяем
            if ($el->tagName == 'img') {
                if($this->downloadImages) {
                    $pr = $this->downloadSinglePhoto($el, $doc);
                    if (is_wp_error($pr)) {
                        $el->parentNode->removeChild($el);
                        $this->addStatus('Не удалось загрузить картинку: ' . $pr->get_error_message());
                    }
                    else if($this->putImagesInGallery) {
                        $imagesHtml .= $pr;
                        $imagesCounter++;
                    }
                } else $el->parentNode->removeChild($el); // Если не загружаем изображения - удаляем тэг
            }
        }

        $this->post['post_content'] = self::getInnerHtml($doc->getElementsByTagName('body')->item(0));

        if($this->downloadImages) {
            // Загрузка элементов альбома
            if (isset($json->org_site_album->value)) {
                $attachedAlbum = $json->org_site_album->value->id;
                $result = $this->downloadAlbum($attachedAlbum, $imagesHtml, $imagesCounter);
                $this->post['post_content'] .= $result;
            } else if($this->putImagesInGallery) { // Добавляем все изображения в галерею
                $ratio = self::$MAX_IMAGES_IN_GALLERY_ROW;
                $counter = count($this->postImages);
                if ($counter < $ratio) $ratio = $counter;
                if($counter != 0) {
                    $result = sprintf(self::$GALLERY_HTML, implode($this->postImages, ','), $ratio, $imagesHtml);
                    $this->post['post_content'] .= $result;
                }
            }
            // Загрузка превью
            if(isset($json->image->value)) {
                $thumbnail = self::$HOST_FOR_ALBUM_IMAGES . $json->image->value->file->value->name_in_storage;
                $hash = $json->image->value->filemd5->value;
                // Загрузка превью картинки
                $r = array_search($hash, $this->imageHashes);
                if($r===false) {
                    $img_id = eisrf_media_sideload_image( $thumbnail ,0, $this->post['post_title'], 'id', $this->timeForImages ); // 0 -> no post-id
                    if (is_wp_error($img_id)) $this->addStatus('Не удалось загрузить превью-картинку: '.$img_id->get_error_message());
                    $this->postThumbnailID = $img_id;
                    array_push($this->postImages,$img_id);
                } else $this->postThumbnailID = $r;
            }
        }

        return true;
    }

    ###
    ### Загрузка единичной фотографии из записи
    ###
    private function downloadSinglePhoto(DOMElement $obj, DOMDocument $doc) {
        $src = $obj->getAttribute('src');
        $alt = $obj->getAttribute('alt');
        // Если нет alt, то в название вложения идет название записи
        if(!$alt) $alt = $this->post['post_title'];

        $alt = str_replace('"', '', $alt);
        $alt = str_replace('\'', '', $alt);

        // В основном все ссылки вида: "media/2017/08/29/1233645435/image_image_1548447.JPG"
        // или "2017/08/29/1233645435/image_image_1548447.JPG"
        $link = parse_url($src);
        if(substr($link['path'],0,1)=='/') $link['path'] = substr($link['path'],1);

        if(substr($link['path'],0,6)!='media/') $link['path'] = 'media/'.$link['path'];
        if(!isset($link['host']))
            $src = self::$HOST_FOR_IMAGES . $link['path'];
        else if(substr($link['path'],0,2)=='//') $src = 'http:'.$src; // для PHP<5.4.7

        $img_id = eisrf_media_sideload_image( $src ,0, $alt, 'id', $this->timeForImages ); // 0 -> no post-id
        if (is_wp_error($img_id)) return $img_id;
        array_push($this->postImages, $img_id);

        /*$srcForMD5 = ABSPATH . substr(parse_url($newImage)['path'], 1);
        $mymd5 = md5_file($srcForMD5);
        $this->add_status($srcForMD5.' -> '.$mymd5);
        $this->image_hashes[$mymd5] = $img_id;*/ // Хэш не совпадает со значением в JSON!

        // Если все изображения загружаются в галерею
        if($this->putImagesInGallery) {
            $newImage = wp_get_attachment_image_src($img_id,'full')[0];
            $obj->parentNode->removeChild($obj);
            $html = sprintf(self::$GALLERY_IMAGE_HTML, $newImage, $newImage, $alt, $img_id, $img_id);
            return $html;
        }

        // Если изображения остаются на своих местах
        // Выясняем размер картинки
        if($this->imageSize!='eisrf_the_same') $img_size = $this->imageSize;
        else {
            $width = $obj->getAttribute('width');
            $height = $obj->getAttribute('height');
            if (!$width) {
                preg_match( '/(?<!-)width\:[ ]*([0-9a-zA-Z\-.,()]*)[;]?/im', $obj->getAttribute('style') ,$matches1);
                $width = $matches1[1];
            }
            if (!$height) {
                preg_match( '/(?<!-)height\:[ ]*([0-9a-zA-Z\-.,()]*)[;]?;/im', $obj->getAttribute('style') ,$matches2);
                $height = $matches2[1];
            }
            if (!$width || !$height) $img_size = self::$DEFAULT_IMG_SIZE;
            else $img_size = array($width,$height);
        }

        $newImage = wp_get_attachment_image_src($img_id,$img_size);
        $obj->setAttribute('src',    $newImage[0]);
        $obj->setAttribute('width',  $newImage[1]);
        $obj->setAttribute('height', $newImage[2]);
        $obj->setAttribute('alt',    $alt);
        $obj->removeAttribute('style');

        // Устанавливаем выравнивание
        switch ($this->imageAlignment) {
            case 'eisrf_image_alignment_left':
                $obj->setAttribute('class', 'alignleft');
                break;
            case 'eisrf_image_alignment_center':
                $obj->setAttribute('class', 'aligncenter');
                break;
            case 'eisrf_image_alignment_right':
                $obj->setAttribute('class', 'alignright');
                break;
        }

        // Добавляем ссылку на изображение
        if($obj->parentNode->tagName=='a')
            $obj->parentNode->setAttribute('href', wp_get_attachment_image_src($img_id, 'full')[0]);
        else {
            $element = $doc->createElement('a');
            $element->setAttribute('href', wp_get_attachment_image_src($img_id, 'full')[0]);
            $obj->parentNode->replaceChild($element, $obj);
            $element->appendChild($obj);
        }

        return true;
    }

    ###
    ### Загрузка полного альбома и создание галереи
    ###
    private function downloadAlbum($id, $extraImagesHtml, $extraImagesCounter) {
        $url = sprintf(self::$ALBUM_URL, $this->siteID, $id);
        $requestArgs = array(
            'cookies'=> $this->cookie,
            'method'=>'GET'
        );
        $request = wp_remote_request($url, $requestArgs);
        if (is_wp_error($request)) {
            $this->addStatus('Не удалось загрузить альбом: '.$request->get_error_message());
        }
        $json = json_decode($request['body']);

        $ratio = self::$MAX_IMAGES_IN_GALLERY_ROW;
        $counter = $extraImagesCounter + count($json);
        if($counter == 0) return '';
        if($counter < $ratio) $ratio = $counter;

        // по какой-то причине название альбома содержится в элементе первого изображения
        $albumTitle = self::cleanQuotesAndSpaces($json[0]->album->value->title);

        $number = 1;
        $html = '';
        $ids = array();

        foreach ($json as $image) {
            $link = self::$HOST_FOR_ALBUM_IMAGES . $image->file->value->name_in_storage;
            $alt = $albumTitle . ' (' . $number . ')';
            $alt = str_replace('"', '', $alt);
            $alt = str_replace('\'', '', $alt);

            $imgID = eisrf_media_sideload_image( $link ,0, $alt, 'id', $this->timeForImages); // 0 -> no post-id
            if (is_wp_error($imgID)) {
                $this->addStatus('Не удалось загрузить картинку: '.$imgID->get_error_message());
                continue;
            };

            array_push($ids, $imgID);
            array_push($this->postGalleryImages, $imgID);
            $this->imageHashes[$imgID] = $image->filemd5->value;

            $fullImgLink = wp_get_attachment_image_src($imgID,'full')[0];
            $html .= sprintf(self::$GALLERY_IMAGE_HTML, $fullImgLink, $fullImgLink, $alt, $imgID, $imgID);
            $number++;
        }

        $html = sprintf(self::$GALLERY_HTML, implode($this->postGalleryImages,','), $ratio, $extraImagesHtml . $html);
        return $html;
    }

    ###
    ### Импорт записи
    ###
    private function importPost() {
        $postID = wp_insert_post(wp_slash($this->post), true);
        $images = array_merge($this->postImages, $this->postGalleryImages);

        // Если при импорте возникла ошибка - выходим
        if ( is_wp_error($postID) ) {
            // Удаляем загруженные изображения
            if($images) {
                foreach ($images as $img) wp_delete_post($img, true);
            }
            $this->death('Не удалось испортировать запись '.$this->post['post_title'].': '.$postID->get_error_message());
        }

        // Прикрепляем изображения к записям
        if($images) {
            foreach ($images as $img) {
                $p = wp_update_post( array(
                    'ID'            => $img,
                    'post_parent'   => $postID
                ), true );
                if(is_wp_error($p)) $this->addStatus('Не удалось прикрепить изображение к записи');
            }
            // Установка превью
            if(!$this->postThumbnailID) $this->postThumbnailID = $images[0];
            set_post_thumbnail($postID, $this->postThumbnailID);
        }

        $link = get_permalink($postID);
        $finish_time = microtime(true) - $this->startTime;
        $post_or_page = (!$this->isPage) ? 'Запись' : 'Страница';

        $this->addStatus($post_or_page.' <a href="' .$link. '">('.$this->post['post_title'].')</a> успешно импортирована за '
            . number_format((float)$finish_time, 2, '.', '') . ' сек.',1);
        return true;
    }

    ###
    ### Получение ajax параметров
    ###
    private function getParams() {
        if(!isset($_POST['eisrf_site_id']) || !isset($_POST['eisrf_cookie']) || !isset($_POST['eisrf_post_id'])) {
            $this->death('Переданы неверные параметры');
        }

        $this->siteID = $_POST['eisrf_site_id'];
        $this->cookie = $_POST['eisrf_cookie'];
        $this->postID = $_POST['eisrf_post_id'];
        $this->isPage = ($_POST['eisrf_is_page']=='true') ? true : false;

        $this->importAsPages =  self::getCheckboxValue('eisrf_import_as_pages');
        if(isset($_POST['eisrf_category_id'])) array_push($this->categoryID, (int)$_POST['eisrf_category_id']);
        $this->removeEmptyParas =  self::getCheckboxValue('eisrf_remove_empty_p');
        $this->downloadImages = self::getCheckboxValue('eisrf_download_images');

        if($this->downloadImages) {
            $this->putImagesInGallery = self::getCheckboxValue('eisrf_put_images_in_gallery');
            $this->imageSize = self::$DEFAULT_IMG_SIZE;
            if (!$this->putImagesInGallery && isset($_POST['eisrf_image_size'])) {
                $image_size = $_POST['eisrf_image_size']; //размер изображений
                $sizes = get_intermediate_image_sizes();
                if (in_array($image_size, $sizes) || $image_size == 'eisrf_the_same')
                    $this->imageSize = $image_size;
            }
            if(isset($_POST['eisrf_image_alignment'])) $this->imageAlignment = $_POST['eisrf_image_alignment'];
        }

        return true;
    }

    ###
    ### Добавление информации к статусу
    ###
    private function addStatus($message, $code = 0) {
        // code = 0 : замечение;
        // code = -1 : фатальная ошибка
        // code = 1 : успешный импорт
        array_push($this->status, array(
            'code'=>$code,
            'message'=>$message
        ));
    }

    ###
    ### Выход с фатальной ошибкой
    ###
    private function death($message) {
        $this->addStatus($message, -1);
        echo json_encode($this->status);
        wp_die();
    }

    ### Получение состояния флажков
    private static function getCheckboxValue($checkbox) {
        if(isset($_POST[$checkbox]) && $_POST[$checkbox] == 'on') return true;
        return false;
    }

    ### Очистка от "неправильных" кавычек и пробелов
    private static function cleanQuotesAndSpaces($string) {
        $string = str_replace('&nbsp;', ' ', $string);
        $string = str_replace('«', '"', $string);
        $string = str_replace('»', '"', $string);
        $string = str_replace('”', '"', $string);
        $string = trim($string, " \t\n\r\0\x0B".chr(0xA0).chr(0xC2)); //удаление пробелов сначала и сконца
        return $string;
    }

    ### Функция оставляет в стиле элемента только заданные свойства
    private static function keepSelectedStyles($string, $styles) {
        preg_match_all($styles,$string,$matches);
        $result = '';
        foreach ($matches[0] as $m) $result .= $m.' ';
        $result = trim($result);
        if ($result) return $result;
        return false;
    }

    ### Функция составляет регулярное выражение для поиска CSS свойств элемента из заданного белого списка
    private static function createWhiteListOfStyles() {
        $whiteList = '/(';
        foreach(self::$STYLES_TO_KEEP as $s) {
            if(is_array($s)) $whiteList .= '(?<!-)' . preg_quote($s[0]).'\:[ ]*'.preg_quote($s[1]).'[;]?|';
            else $whiteList .= '(?<!-)' . preg_quote($s) .'\:[ ]*([0-9a-zA-Z\-.,()]*)[;]?|';
        }
        $whiteList = substr($whiteList,0,-1) . ')/m';
        return $whiteList;
    }

    ### Получение внутреннего кода элемента DOMElement
    private static function getInnerHtml($node) {
        $innerHTML= '';
        $children = $node->childNodes;
        foreach ($children as $child) {
            $innerHTML .= $child->ownerDocument->saveXML( $child );
        }
        return $innerHTML;
    }
}

function eisrf_import_post() {
    check_ajax_referer( 'eisrf_form_nonce', 'nonce_code' );
    new EISRF_Post_Importer();
    wp_die();
}

if( wp_doing_ajax() ) {
    add_action( 'wp_ajax_eisrf_import_post', 'eisrf_import_post' );
}