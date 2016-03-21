<?php
    header('Content-type: application/json');
    include 'class.phpmailer.php';

    $filters = array
    (
        "name" => FILTER_SANITIZE_STRING,
        "email"=> FILTER_VALIDATE_EMAIL,
        "message" => FILTER_SANITIZE_STRING
    );

    $input = filter_input_array(INPUT_POST, $filters);
    if(isset($input['email']) && isset($input['name']) && isset($input['message'])){
        $mail = new PHPMailer;
        $mail->setFrom($input['email'], $input['name']);
        $mail->addReplyTo($input['email'], $input['name']);
        $mail->addAddress('info@tothepoint.company', 'ToThePoint');
        $mail->Subject = 'ToThePoint - Contact formulier';
        $mail->msgHTML($input['message']);
        $mail->AltBody = $input['message'];

        if (!$mail->send()) {
            echo json_encode(array('type' => 'danger', 'message' => 'The message has not been sent. Please try again later.'));
        } else {
            echo json_encode(array('type' => 'success', 'message' => 'Your message has been sent.'));
        }
    }
    else
    {
        echo json_encode(array('type' => 'danger', 'message' => 'The message has not been sent. Please make sure all fields are filled in properly.'));
    }
