<?php

$result = db_query('SELECT cp.directory, p.uri, COALESCE((tn.tid != 29), 1) as strip_trans, p.nid FROM project_projects AS p INNER JOIN cvs_projects AS cp ON p.nid = cp.nid INNER JOIN node AS n on p.nid = n.nid LEFT JOIN term_node tn ON n.vid = tn.vid AND tn.tid IN (13, 14, 15, 29, 32, 96)');

$fileobj = new SplFileObject(dirname(__FILE__) . '/project-migrate-info', 'w');
while ($row = db_fetch_object($result)) {
  $fileobj->fwrite(sprintf('%s %s %d' . PHP_EOL, $row->directory, $row->uri, $row->nid == 3060 ? 0 : $row->strip_trans));
}
