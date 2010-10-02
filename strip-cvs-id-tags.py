#!/usr/bin/env python
"""
Python script to remove CVS tags from a whole tree of files.

Locates the files and uses Sed to do the hard work.
"""

from glob import iglob as glob
import os
import subprocess
import sys

# List of file extensions we want to work with.
EXTENSIONS = (
    '.conf',
    '.css',
    '.drush',
    '.htm',
    '.html',
    '.inc',
    '.info',
    '.ini',
    '.install',
    '.js',
    '.module',
    '.mysql',
    '.pgsql',
    '.php',
    '.pl',
    '.po',
    '.pot',
    '.profile',
    '.sh',
    '.sql',
    '.template',
    '.test',
    '.txt',
    '.xml',
    '.xhtml',
)

FILE_NAMES = (
    'INSTALL',
    'README',
    'readme',
)

SED_FILE = os.path.join(os.path.dirname(__file__), 'strip-cvs-id-tags.sed')

def main():
    try:
        # If a dirname is passed as the first parameter, use that.
        path = sys.argv[1]
    except IndexError:
        # Fall back on the current working directory.
        path = os.getcwd()

    if not os.path.isdir(path):
        sys.exit('"%s" is not a directory.' % path)

    for root, dirs, files in os.walk(path):
        # Don't mess with VCS files.
        if 'CVS' in dirs:
            dirs.remove('CVS')
        if '.git' in dirs:
            dirs.remove('.git')

        for filename in files:
            name, extension = os.path.splitext(filename)
            if extension.lower() in EXTENSIONS or name in FILE_NAMES:
                abs_path = os.path.realpath(os.path.join(path, root, filename))
                print 'Passing file %s/%s to Sed.' % (root, filename)
                subprocess.Popen(('sed', '-Ei', '', '-f', SED_FILE, abs_path))

if __name__ == "__main__":
    main()

