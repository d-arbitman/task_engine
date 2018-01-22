<?php
// so that we can use MySQLi
require_once ("BindParam.php");
// for basic email functions
include_once('Mail.php');
include_once('Mail/mime.php');

/*
$tasks=new task_engine($mysqli);

For the exec and execBackground task types, the target needs to be sanitized

To add a task:
  $tasks->add_task (['what'=>'sendEmail', 'target'=>'target@example.com','dueDate'=>date('Y-m-d H:i:s'), 'text'=>'Something', 'subject'=>'This is my subject'], 'template'=>'something']);
To delete a task:
  $tasks->delete_task ($taskId);
To update a task:
  $tasks->update_task ($taskId, [array with variables to update]);
To clone a task:
  $task->clone_task ($taskId /*, optional new due date for the task  );
To run everything that is due now:
  $tasks->run_current_tasks();
*/

function task_dispatch ($task) {
  // email settings
  $port = "465";
  $host = "ssl://host.example.com";
  $email_address ="email@example.com";
  $email_password = "password";

  // get target directory
  $t=explode('/', $task['target']);
  array_pop($t);
  $dir=implode('/', $t);

  if ($task['what']=='sendEmail') {
    print "Sending email to " . $task['target'] . "\n";
    $mime = new Mail_mime();

    // add a header and footer if they exist
    $head=((isset($task['template']) && file_exists($task['template'] . '.header'))?file_get_contents($task['template'] . '.header'):'');
    $foot=((isset($task['template']) && file_exists($task['template'] . '.footer'))?file_get_contents($task['template'] . '.footer'):'');

    $mime->setHTMLBody($head . $task['text'] . $foot);

    $attach=explode(',', $task['files']);
    if (isset($attach) && is_array($attach)) {
      foreach($attach as $at){
        $mime->addAttachment($at);
      }
    } else {
      if(isset($attach)) {
        $mime->addAttachment($attach);
      }
    }

    // set email headers
    $hdrs = array ('MIME-Version' => '1.0','From' => $email_address,'To' => $task['target'],'Subject' => $task['subject'], 'Bcc' => '');
    $body = $mime->get();
    $hdrs = $mime->headers($hdrs);
    $smtp =new Mail;
    try {
      $smtp->factory('smtp', array ('host' => $host,'port' => $port,'auth' => true,'username' => $email_address,'password' => $email_password))->send($task['target'], $hdrs, $body);
      return 1;
    } catch (Exception $e) {
      logError("Error in sendmail: " . $e->getMessage());
      return $e->getMessage();
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
class task_engine {
  private $mysqliSingleton;
  public $task_table;

  function __construct($mysqli, $t='tasks') {
    $this->mysqliSingleton=$mysqli;
    $this->task_table=$t;
  }

  function get_current_tasks () {
  //return array of tasks that need to run as of now but, haven't started yet
    $arr=[];
  $sth=$this->mysqliSingleton->prepare("select id, what, target from " . $this->task_table . " where dueDate<=now() and dateStarted is null and deleted<>'1' order by dueDate");
    $sth->execute ();
    $r=$sth->get_result();
    if ($r->num_rows!=0) {
      while ($row=$r->fetch_assoc()) {
        $arr[$row['id']]=$row['what'] . ':' . $row['target'];
      }
    }
    return $arr;
  }

  function run_current_tasks () {
    $sth=$this->mysqliSingleton->prepare("select * from " . $this->task_table . " where dueDate<now() and dateStarted is null and deleted<>'1' order by dueDate");
    $sth->execute ();
    $r=$sth->get_result();
    if ($r->num_rows!=0) {
      while ($row=$r->fetch_assoc()) {
        $this->update_task($row['id'], ['dateStarted'=>date('Y-m-d H:i:s'), 'running'=>1]);
        $t=task_dispatch($row);
        $this->update_task($row['id'], ['dateCompleted'=>date('Y-m-d H:i:s'), 'running'=>0, 'error'=>(($t!=1)?$t:null)]);
      }
    }

  }

  function delete_task ($id) {
    $sth=$this->mysqliSingleton->prepare("update " . $this->task_table . " set deleted='1' where id=?");
    $sth->bind_param('i', $id);
    $sth->execute ();
  }

  function reset_unfinished_tasks () {
    $sth=$this->mysqliSingleton->prepare("update " . $this->task_table . " set dateStarted=null where running<>'0' and dateStarted is not null and dateCompleted is null and error is null and deleted<>'1'");
    $sth->execute ();
  }

  function add_task($vars) {
    $sql='';
    $keys=array_keys($vars);
    $values=array_values($vars);
    $bindParam = new BindParam();
    $qmarks=[];
    for ($i=0; $i<count($keys); $i++) {
      $qMarks[]='?';
    }
    $sql='insert into ' . $this->task_table . ' (' . implode(', ', $keys) . ') values (' . implode(', ', $qMarks) . ')';
    $sth=$this->mysqliSingleton->prepare($sql);
    for ($i=0; $i<count($values); $i++) {
      $bindParam->add('s', $values[$i]);
    }
    $param=$bindParam->get();
    $type=array_shift($param);
    call_user_func_array('mysqli_stmt_bind_param', array_merge (array($sth, $type), refValues($param)));
    if ($sth->execute()) {
      return $this->mysqliSingleton->insert_id;
    } else {
      return -1;
    }
  }

  function get_task ($vars) {
    $sql='select * from ' . $this->task_table . ' where ';
    $keys=array_keys($vars);
    $values=array_values($vars);
    $bindParam = new BindParam();
    $qmarks=[];
    for ($i=0; $i<count($keys); $i++) {
      if (strpos($values[$i], '%')!==false || strpos($values[$i], '_')!==false || strpos($values[$i], '[^')!==false || strpos($values[$i], '[!')!==false) {
        $sql.="$keys[$i] like ? and ";
      } else {
        $sql.="$keys[$i] = ? and ";
      }
    }
    $sql=substr($sql, 0, -5);
    $sth=$this->mysqliSingleton->prepare($sql);
    for ($i=0; $i<count($values); $i++) {
      $bindParam->add('s', $values[$i]);
    }
    $param=$bindParam->get();
    $type=array_shift($param);
    call_user_func_array('mysqli_stmt_bind_param', array_merge (array($sth, $type), refValues($param)));
    $sth->execute();
    $r=$sth->get_result();
    if ($r->num_rows) {
      return $r->fetch_assoc();
    } else {
      return null;
    }
  }

  function clone_task ($id, $due_date=null) {
    if ($due_date==null) {
      $due_date=date('Y-m-d H:i:s');
    }
    $my_task=$this->get_task(['id'=>$id]);
    if (isset($my_task)) {
      if (isset($my_task['id'])) {unset($my_task['id']);}
      if (isset($my_task['dueDate'])) {unset($my_task['dueDate']);}
      if (isset($my_task['dateStarted'])) {unset($my_task['dateStarted']);}
      if (isset($my_task['dateCompleted'])) {unset($my_task['dateCompleted']);}
      if (isset($my_task['deleted'])) {unset($my_task['deleted']);}
      if (isset($my_task['dateAdded'])) {unset($my_task['dateAdded']);}
      if (isset($my_task['running'])) {unset($my_task['running']);}
      if (isset($my_task['error'])) {unset($my_task['error']);}
      $my_task['dueDate']=$dueDate;
      return $this->add_task($my_task);
    } else {
      return -1;
    }
  }

  function run_task ($id) {
    $sth=$this->mysqliSingleton->prepare("select * from " . $this->task_table . " where id=? and deleted<>'1'");
    $sth->bind_param('i', $id);
    $sth->execute();
    $r=$sth->get_result();
    if ($r->num_rows!=0) {
      $row=$r->fetch_assoc();
      $this->update_task($row['id'], ['dateStarted'=>date('Y-m-d H:i:s'), 'running'=>1]);
      task_dispatch($row);
      $this->update_task($row['id'], ['dateCompleted'=>date('Y-m-d H:i:s'), 'running'=>0, 'error'=>(($t!=1)?$t:null)]);
    }
  }

  function update_task ($id, Array $vars) {
    $sql='';
    $keys=array_keys($vars);
    $values=array_values($vars);
    $bindParam = new BindParam();
    $sql='update ' . $this->task_table . ' set ' . implode('=?, ', $keys) . '=? where id=?';
    $sth=$this->mysqliSingleton->prepare($sql);
    for ($i=0; $i<count($values); $i++) {
      $bindParam->add('s', $values[$i]);
    }
    $bindParam->add('i', $id);
    $param=$bindParam->get();
    $type=array_shift($param);
    call_user_func_array('mysqli_stmt_bind_param', array_merge (array($sth, $type), refValues($param)));
    $sth->execute();
  }

}
