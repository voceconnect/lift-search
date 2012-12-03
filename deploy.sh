#! /bin/bash
# Another modification based on the below...
# A modification of Dean Clatworthy's deploy script as found here: https://github.com/deanc/wordpress-plugin-git-svn
# The difference is that this script lives in the plugin's git repo & doesn't require an existing SVN repo.

USAGE="
Usage: \n 
-n = Plugin slug name ex. page-links-to. DEFAULT: use working  directory(pwd) \n 
-u = REQUIRED. WP  SVN  username \n  
-f = Plugin  mainfile.  DEFAULT: {plugin  slug}.php  \n  
-p = SVN repo  path.  DEFAULT: http://plugins.svn.wordpress.org/{plugin slug}  \n 
-d = Git  directory. DEFAULT:  use working  directory(pwd). "
while getopts  ":n:f:p:u:d:h" OPTIONS; do case  $OPTIONS in n )  PLUGINSLUG=$OPTARG;; f ) MAINFILE=$OPTARG;;  p )
SVNURL=$OPTARG;;  u  )  SVNUSER=$OPTARG;;  d  )  CURRENTDIR=$OPTARG;;  h  )  echo  -e  $USAGE;  exit;  esac  done

# Set the variables based on flag input or defaults
if [ -z "$PLUGINSLUG" ];then PLUGINSLUG=${PWD##*/}; fi
if [ -z "$SVNUSER" ]; then
	echo "Enter your WP SVN username"
	read input_variable
	SVNUSER=$input_variable;	
fi
if [ -z "$MAINFILE" ];then MAINFILE="$PLUGINSLUG.php"; fi
if [ -z "$CURRENTDIR" ];then CURRENTDIR=`pwd`; fi
GITPATH="$CURRENTDIR/" # this file should be in the base of your git repository
SVNPATH="/tmp/$PLUGINSLUG" # path to a temp SVN repo. No trailing slash required and don't add trunk.
if [ -z "$SVNURL" ];then SVNURL="http://plugins.svn.wordpress.org/$PLUGINSLUG"; fi

# Let's begin...
echo -e "Preparing to deploy wordpress plugin... \n"

# Check if SVN repo exists
if [[ `svn info $SVNURL` -ne 1 ]]; then echo "SVN repo does not exist. Exiting..."; exit 1; fi

# Check version in readme.txt is the same as plugin file
NEWVERSION1=`grep "^Stable tag" $GITPATH/readme.txt | awk '{print $NF+0}'`
echo "readme version: $NEWVERSION1"
NEWVERSION2=`grep "^  Version" $GITPATH/$MAINFILE | awk '{print $NF+0}'`
echo "$MAINFILE version: $NEWVERSION2"

if [ "$NEWVERSION1" != "$NEWVERSION2" ]; then echo "Versions don't match. Exiting...."; exit 1; fi

echo "Versions match in readme.txt and PHP file. Let's proceed..."

#check if tag of that version already exists and exit script
cd $GITPATH
tag_exists=`git ls-remote --tags |  grep "tags/$NEWVERSION1" | grep -v grep | awk ' {print $2}'`
if [ "$tag_exists" ]
    then
        promptyn () {
            while true; do
                read -p "$1 " yn
                case $yn in
                    [Yy]* ) return 0;;
                    [Nn]* ) return 1;;
                    * ) echo "(yes or no)";;
                esac
            done
        }
        if promptyn "Tag already exists. Do you wish to continue?"; then
            echo "Continuing...";
        else
            echo "Exiting..."; exit 1; 
        fi
fi

cd $GITPATH
echo -e "Enter a commit message for this new version: \c"
read COMMITMSG
git commit -am "$COMMITMSG"

echo "Tagging new version in git"
git tag -a "$NEWVERSION1" -m "Tagging version $NEWVERSION1"

echo "Pushing latest commit to origin, with tags"
git push origin master
git push origin master --tags

echo "Creating local copy of SVN repo ..."
svn co $SVNURL $SVNPATH

echo "Exporting the HEAD of master from git to the trunk of SVN"
git checkout-index -a -f --prefix=$SVNPATH/trunk/

echo "Ignoring github specific files, test directories, and deployment script"
svn propset svn:ignore "deploy.sh
README.md
test
tests
.git
.gitignore" "$SVNPATH/trunk/"

echo "Changing directory to SVN and committing to trunk"
cd $SVNPATH/trunk/
# Add all new files that are not set to be ignored
svn status | grep -v "^.[ \t]*\..*" | grep "^?" | awk '{print $2}' | xargs svn add
# Delete all missing files 
svn status | grep -v "^.[ \t]*\..*" | grep "^!" | awk '{print $2}' | xargs svn del
svn commit --username=$SVNUSER -m "$COMMITMSG"

echo "Creating new SVN tag & committing it"
cd $SVNPATH
svn copy trunk/ tags/$NEWVERSION1/
cd $SVNPATH/tags/$NEWVERSION1
svn commit --username=$SVNUSER -m "Tagging version $NEWVERSION1"

echo "Removing temporary directory $SVNPATH"
rm -fr $SVNPATH/

echo "*** FIN ***"
