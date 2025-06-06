<?php

add_action('rest_api_init', function () {
    register_rest_route('custom-translate/v1', '/texts', [
        'methods' => 'GET',
        'callback' => 'get_translated_texts_by_lang',
        'args' => [
            'lang' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ]
        ],
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('custom-translate/v1', '/save-texts', [
        'methods' => 'POST',
        'callback' => 'save_extracted_texts_to_db',
        'permission_callback' => '__return_true',
    ]);

     register_rest_route('custom-translate/v1', '/available-languages', [
        'methods' => 'GET',
        'callback' => 'get_avail_languages',
        'permission_callback' => '__return_true',
    ]);



    // For Courses
    register_rest_route('custom-translate/v1', '/courses-content', [
        'methods' => 'GET',
        'callback' => function () {
            global $wpdb;
            $courses = $wpdb->get_results("SELECT ID, post_title, post_content FROM {$wpdb->prefix}posts WHERE post_type = 'sfwd-courses' AND post_status = 'publish'");
            $lessons = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->prefix}posts WHERE post_type = 'sfwd-lessons' AND post_status = 'publish'");
            $topics = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->prefix}posts WHERE post_type = 'sfwd-topic' AND post_status = 'publish'");

            return rest_ensure_response([
                'courses' => $courses,
                'lessons' => $lessons,
                'topics' => $topics
            ]);
        },
        'permission_callback' => '__return_true'
    ]);

    // Stats endpoint
    register_rest_route('custom-translate/v1', '/stats', [
        'methods' => 'GET',
        'callback' => 'get_extraction_stats',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
    
    // All texts endpoint
    register_rest_route('custom-translate/v1', '/all-texts', [
        'methods' => 'GET',
        'callback' => 'get_all_extracted_texts',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // For Quizzes
    register_rest_route('custom-translate/v1', '/quizzes', [
        'methods' => 'GET',
        'callback' => function () {
            global $wpdb;

            $quizzes = $wpdb->get_results("
                SELECT ID, post_title
                FROM {$wpdb->prefix}posts
                WHERE post_type = 'sfwd-quiz' AND post_status = 'publish'
            ");

            return rest_ensure_response($quizzes);
        },
        'permission_callback' => '__return_true'
    ]);

    // For Questions
    register_rest_route('custom-translate/v1', '/quiz-questions', [
        'methods' => 'GET',
        'callback' => function () {
            global $wpdb;

            $questions = $wpdb->get_results("
                SELECT id, question
                FROM {$wpdb->prefix}learndash_pro_quiz_question
                WHERE question IS NOT NULL AND question != ''
            ");

            return rest_ensure_response($questions);
        },
        'permission_callback' => '__return_true'
    ]);


    // For Answers
    register_rest_route('custom-translate/v1', '/quiz-answers', [
        'methods' => 'GET',
        'callback' => function () {
            global $wpdb;

            $rows = $wpdb->get_col("
                SELECT answer_data
                FROM {$wpdb->prefix}learndash_pro_quiz_question
                WHERE answer_data IS NOT NULL AND answer_data != ''
            ");
            $answers = [];
            foreach ($rows as $row) {
                // regex to capture any *_answer content
                preg_match_all('/\*?_answer";s:\d+:"([^"]+)"/', $row, $matches);
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $answer) {
                        if (preg_match('/[A-Za-z]/', $answer)) {
                            $answers[] = trim($answer);
                        }
                    }
                }
            }
            // إزالة التكرارات
            $answers = array_values(array_unique($answers));
            return rest_ensure_response(['answers' => $answers]);
        },
        'permission_callback' => '__return_true'
    ]);

});
