<?php
// Typ treści jak na RS2, żeby moduł miał co pokazać w mapie i schemacie Service.
add_action('init', function () { register_post_type('cms-service', array('public' => true, 'label' => 'Usługi')); });
