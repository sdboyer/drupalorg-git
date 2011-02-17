<?php

// First, delete some projects nodes.

$kill = array(
  103482, // sandbox
);

foreach ($kill as $nid) {
  node_delete($nid);
}

$result = db_query('SELECT cp.directory, p.uri, COALESCE((tn.tid NOT IN (13, 29)), 1) as strip_trans, p.nid FROM project_projects AS p INNER JOIN cvs_projects AS cp ON p.nid = cp.nid INNER JOIN node AS n on p.nid = n.nid LEFT JOIN term_node tn ON n.vid = tn.vid AND tn.tid IN (13, 14, 15, 29, 32, 96)');

$fileobj = new SplFileObject(dirname(__FILE__) . '/project-migrate-info', 'w');
while ($row = db_fetch_object($result)) {
  $function = '_tggm_exception_' . $row->uri;
  if (function_exists($function) && !$function($row)) {
    // Skip this item if the exception function exists and returns FALSE.
    continue;
  }
  $fileobj->fwrite(sprintf('%s,%s,%d,%d' . PHP_EOL, $row->directory, $row->uri, $row->strip_trans, $row->nid));
}

function _tggm_exception_sandbox($row) {
  // The 'sandbox' catchall project would cause us to import ALL sandboxes. No freakin way.
  return FALSE;
}