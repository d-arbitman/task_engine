<?php
require_once ("task_engine.php");
// for basic email functions
include_once ('Mail.php');
include_once ('Mail/mime.php');

/*
$tasks=new task_engine ($mysqli);

For the exec and execBackground task types, the target should only be generated programmatically

To add a task:
  $tasks->add_task (['what'=>'sendEmail', 'target'=>'target@example.com','dueDate'=>date ('Y-m-d H:i:s'), 'text'=>'Something', 'subject'=>'This is my subject'], 'template'=>'something']);
To delete a task:
  $tasks->delete_task ($taskId);
To update a task:
  $tasks->update_task ($taskId, [array with variables to update]);
To clone a task:
  $task->clone_task ($taskId /*, optional new due date for the task  );
To run everything that is due now:
  $tasks->run_current_tasks ();
*/

function task_dispatch ($task) {
  // email settings
  $port = "465";
  $host = "ssl://host.example.com";
  $email_address ="email@example.com";
  $email_password = "password";

  // get target directory
  $t=explode ('/', $task['target']);
  array_pop ($t);
  $dir=implode ('/', $t);

  if ($task['what']=='sendEmail') {
    print "Sending email to " . $task['target'] . "\n";
    $mime = new Mail_mime ();

    // add a header and footer if they exist
    $head= ( (isset ($task['template']) && file_exists ($task['template'] . '.header'))?file_get_contents ($task['template'] . '.header'):'');
    $foot= ( (isset ($task['template']) && file_exists ($task['template'] . '.footer'))?file_get_contents ($task['template'] . '.footer'):'');

    $mime->setHTMLBody ($head . $task['text'] . $foot);

    $attach=explode (',', $task['files']);
    if (isset ($attach) && is_array ($attach)) {
      foreach ($attach as $at){
        $mime->addAttachment ($at);
      }
    } else {
      if (isset ($attach)) {
        $mime->addAttachment ($attach);
      }
    }

    // set email headers
    $hdrs = array ('MIME-Version' => '1.0','From' => $email_address,'To' => $task['target'],'Subject' => $task['subject'], 'Bcc' => '');
    $body = $mime->get ();
    $hdrs = $mime->headers ($hdrs);
    $smtp =new Mail;
    try {
      $smtp->factory ('smtp', array ('host' => $host,'port' => $port,'auth' => true,'username' => $email_address,'password' => $email_password))->send ($task['target'], $hdrs, $body);
      return 1;
    } catch (Exception $e) {
      logError ("Error in sendmail: " . $e->getMessage ());
      return $e->getMessage ();
    }
  } elseif ($task['what']=='exec') {
    return system ("cd " . $dir . "; " . $task['target'] . " " . $task['id']);
  } elseif ($task['what']=='execBackground') {
    system ("cd " . $dir . "; " . $task['target'] . " " . $task['id'] . " &");
    return 1;
  } else {
    return 'Unknown task';
  }
}
