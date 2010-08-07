#!/bin/sh

CONCURRENCY=3
REPOSITORY=/cvs/drupal-contrib
PREFIX=contributions/modules
DESTINATION=contributions
LOG_PATH=logs
DIFFLOG_PATH=difflog

mkdir -p $LOG_PATH
ls -d $REPOSITORY/$PREFIX/* | xargs -I% basename % | egrep -v "Attic" | xargs --max-proc $CONCURRENCY -I% sh -c "php5 import-project.php ./cvs2git.options $REPOSITORY $PREFIX/% $DESTINATION/%.git | tee $LOG_PATH/%.log"
mkdir -p $DIFFLOG_PATH
ls -d $DESTINATION/* | sed 's/.git$//' | xargs -I% basename % | xargs --max-proc $CONCURRENCY -I% sh -c "php5 test-project.php $REPOSITORY $PREFIX/% $DESTINATION/%.git | tee $DIFFLOG_PATH/%.log"
