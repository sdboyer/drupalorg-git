<?php

define('DEBUG', FALSE);

function git_invoke($command, $fail_safe = FALSE, $repository_path = NULL, $env = NULL) {
  if (!isset($env)) {
    $env = $_ENV;
  }
  if ($repository_path) {
    $env['GIT_DIR'] = $repository_path;
  }

  $descriptor_spec = array(
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
  );

  if (DEBUG) {
    git_log('Invoking ' . $command, 'DEB');
  }

  $process = proc_open($command, $descriptor_spec, $pipes, NULL, $env);
  if (is_resource($process)) {
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $return_code = proc_close($process);

    if ($return_code != 0 && !$fail_safe) {
      throw new Exception("Invocation of '" . $command . "' failed with return code " . $return_code .": \n" . $stdout . $stderr);
    }

    return $stdout;
  }
}

function git_log($message, $type = 'INFO') {
  echo '[' . date('Y-m-d H:i:s') . '] [' . $type . '] ' . $message . "\n";
}