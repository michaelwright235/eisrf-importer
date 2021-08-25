<?php

/* Модифицированные функции WordPress с добавлением возможности настройки времени (в формате Unix) */
function eisrf_media_sideload_image( $file, $post_id, $desc = null, $return = 'html', $time = null) {
    if ( ! empty( $file ) ) {

        // Set variables for storage, fix file filename for query strings.
        preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $file, $matches );
        if ( ! $matches ) {
            return new WP_Error( 'image_sideload_failed', __( 'Invalid image URL' ) );
        }

        $file_array = array();
        $file_array['name'] = basename( $matches[0] );

        // Download file to temp location.
        $file_array['tmp_name'] = download_url( $file );

        // If error storing temporarily, return the error.
        if ( is_wp_error( $file_array['tmp_name'] ) ) {
            return $file_array['tmp_name'];
        }

        // Добавляем дату
        $post_data = array();
        $post_data['post_date'] = date('Y-m-d H:i:s', $time);

        // Do the validation and storage stuff.
        $id = eisrf_media_handle_sideload( $file_array, $post_id, $desc, $post_data, $time);

        // If error storing permanently, unlink.
        if ( is_wp_error( $id ) ) {
            @unlink( $file_array['tmp_name'] );
            return $id;
            // If attachment id was requested, return it early.
        } elseif ( $return === 'id' ) {
            return $id;
        }

        $src = wp_get_attachment_url( $id );
    }

    // Finally, check to make sure the file has been saved, then return the HTML.
    if ( ! empty( $src ) ) {
        if ( $return === 'src' ) {
            return $src;
        }

        $alt = isset( $desc ) ? esc_attr( $desc ) : '';
        $html = "<img src='$src' alt='$alt' />";
        return $html;
    } else {
        return new WP_Error( 'image_sideload_failed' );
    }
}

function eisrf_media_handle_sideload( $file_array, $post_id, $desc = null, $post_data = array(), $time = null) {
    $overrides = array('test_form'=>false);

    if(!$time) {
        $time = current_time('mysql');
        if ($post = get_post($post_id)) {
            if (substr($post->post_date, 0, 4) > 0)
                $time = $post->post_date;
        }
    } else $time = date('Y/m', $time);

    $file = wp_handle_sideload( $file_array, $overrides, $time );
    if ( isset($file['error']) )
        return new WP_Error( 'upload_error', $file['error'] );

    $url = $file['url'];
    $type = $file['type'];
    $file = $file['file'];
    $title = preg_replace('/\.[^.]+$/', '', basename($file));
    $content = '';

    // Use image exif/iptc data for title and caption defaults if possible.
    if ( $image_meta = wp_read_image_metadata( $file ) ) {
        if ( trim( $image_meta['title'] ) && ! is_numeric( sanitize_title( $image_meta['title'] ) ) )
            $title = $image_meta['title'];
        if ( trim( $image_meta['caption'] ) )
            $content = $image_meta['caption'];
    }

    if ( isset( $desc ) )
        $title = $desc;

    // Construct the attachment array.
    $attachment = array_merge( array(
        'post_mime_type' => $type,
        'guid' => $url,
        'post_parent' => $post_id,
        'post_title' => $title,
        'post_content' => $content,
    ), $post_data );

    // This should never be set as it would then overwrite an existing attachment.
    unset( $attachment['ID'] );

    // Save the attachment metadata
    $id = wp_insert_attachment($attachment, $file, $post_id);
    if ( !is_wp_error($id) )
        wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );

    return $id;
}

// Получение размеров изображений вместе с их размером в пикселях
function eisrf_get_image_sizes( $unset_disabled = true ) {
    $wais = & $GLOBALS['_wp_additional_image_sizes'];
    $sizes = array();

    foreach ( get_intermediate_image_sizes() as $_size ) {
        if ( in_array( $_size, array('thumbnail', 'medium', 'medium_large', 'large') ) ) {
            $sizes[ $_size ] = array(
                'width'  => get_option( "{$_size}_size_w" ),
                'height' => get_option( "{$_size}_size_h" ),
                'crop'   => (bool) get_option( "{$_size}_crop" ),
            );
        }
        elseif ( isset( $wais[$_size] ) ) {
            $sizes[ $_size ] = array(
                'width'  => $wais[ $_size ]['width'],
                'height' => $wais[ $_size ]['height'],
                'crop'   => $wais[ $_size ]['crop'],
            );
        }

        // size registered, but has 0 width and height
        if( $unset_disabled && ($sizes[ $_size ]['width'] == 0) && ($sizes[ $_size ]['height'] == 0) )
            unset( $sizes[ $_size ] );
    }

    return $sizes;
}

// Удаление рубрики "Без рубрики" для статей, уже имеющих какую-либо рубрику
function deleteUselessCategory() {
    $objects = get_objects_in_term( 1, 'category' );

    foreach($objects as $obj) {
        $cats = wp_get_post_categories($obj);
        $useless = false;
        foreach ($cats as $c) {
            if($c != 1 ) $useless = true;
        }
        if($useless) {
            wp_remove_object_terms( $obj, 1, 'category' );
        }
    }
}