#!/bin/sh

CONCURRENCY=3
REPOSITORY=/cvs/drupal-contrib
PREFIX=contributions/modules
DESTINATION=contributions

ls -d $REPOSITORY/$PREFIX/* | xargs -I% basename % | egrep -v "Attic" | xargs --max-proc $CONCURRENCY -I% sh -c "php5 import-project.php ./cvs2git.options $REPOSITORY $PREFIX/% $DESTINATION/%.git | tee %.log"
