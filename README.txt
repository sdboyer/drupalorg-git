This is the official set of scripts used to migrate the Drupal.org CVS
repositories into git. Running import-all.sh will migrate the entirety of
the designated local Drupal CVS mirror into an output directory that conforms
to the repository layout we have settled on.

We utilize a modified version of cvs2svn in order to kill keywords, which
confound the history and make merges difficult. Hence the included patchfile;
note that the patch cvs2svn-2.3.0.patch must be applied to
cvs_revision_manager.py in your cvs2svn_lib directory. You may need to run
`find / -name cvs_revision_manager.py` to find this file if you installed it
using your distro's package manager.
