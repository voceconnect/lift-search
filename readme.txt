=== Lift: Search for WordPress ===
Contributors: voceplatforms
Tags: search, cloudsearch, amazon, aws
Requires at least: 3.4.2
Tested up to: 3.5
Stable tag: 1.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Improves WordPress search using Amazon CloudSearch.

== Description ==

Lift leverages the search index power of Amazon CloudSearch to improve your
 WordPress-powered site’s search experience. Learn more at: 
[getliftsearch.com](http://getliftsearch.com/)

Minimum requirements:

* WordPress Version 3.4.2
* PHP Version 5.3
* Amazon Web Services account with CloudSearch enabled

== Installation ==

For full documentation see 
[getliftsearch.com/documentation/](http://getliftsearch.com/documentation/)

Minimum requirements:

* WordPress Version 3.4.2
* PHP Version 5.3
* Amazon Web Services account with CloudSearch enabled

1. Upload the `lift-search` directory to the `/wp-content/plugins/` directory

2. Activate the plugin through the 'Plugins' menu in WordPress

3. Enter your Amazon Access Key ID and Secret Access Key.

4. Click "Save Configuration." If the test fails, check that both of 
your keys are entered correctly and that you are connected to Amazon. 

5. Enter a Search Domain Name. This must be a unique string to your AWS account.
The domain name string can only contain the following characters: a-z (lowercase),
0-9, and - (hyphen). Uppercase letters and underscores are not allowed. 
The string has a max length of 28 characters.

6. Click "Save Domain". If the domain does not exist, Lift will ask if you would 
like the domain created for you. Click the link and the plugin will create and 
configure everything for you.

7. Once the tests are successful, you're now ready to go. Click "View Lift 
Search Dashboard". Once CloudSearch is ready, Lift will begin to index your site. 
This can take a few hours depending on the amount of content on your site.

== Frequently Asked Questions ==

= What are the requirements to use this plugin? =

* WordPress Version 3.4.2
* PHP Version 5.3
* Amazon Web Services account with CloudSearch enabled

= Does Lift support WordPress multisite? =
Multisite is supported with each site in the network having it's own search
domain. Due to this, searching across sites in a network is not supported, however,
may be added at a later date if there is interest.

= How much does Lift cost? =
There is no charge for the plugin. The only charges you incur are for usage of 
Amazon CloudSearch. You can [learn more](http://aws.amazon.com/cloudsearch/pricing/) about expected costs at Amazon's
Clousearch site.

= Does Lift support languages other than English? =
Currently, Amazon CloudSearch only supports indexing documents in English. Once
other languages are supported, Lift will be updated. Also, a future update will
add li8n support for the setup and status pages.

= How do I set up Google Analytics to track searches? =
Since Lift hooks in to the standard WordPress search, if you are already tracking
searches through Google Analytics you don't need to do anything. If you would
like to know how to do this, see the [Google Analytics docs](http://www.google.com/url?q=http%3A%2F%2Fsupport.google.com%2Fanalytics%2Fbin%2Fanswer.py%3Fhl%3Den%26answer%3D1012264). 
The Query Parameter to enter (step #8) is "s".

= What index fields are used when Lift configures a new search domain? =
The index fields are set as follows:
`Field                    Type     Facet          Result   Search
-----------------------  -------  -------------  ------   -------------
id                       uint     Yes (default)  No       Yes (default)
post_author              uint     Yes (default)  No       Yes (default)
post_author_name         text     No             Yes      Yes (default)
taxonomy_category_id     literal  Yes            No       No
taxonomy_category_label  text     No             No       Yes (default)
post_content             text     No             No       Yes (default)
post_date_gmt            uint     Yes (default)  No       Yes (default)
post_status              literal  Yes            No       No
post_title               text     No             Yes      Yes (default)
post_type                literal  Yes            No       Yes
comment_count            uint     Yes (default)  No       Yes (default)
taxonomy_tags_id         literal  Yes            No       No
taxonomy_tags_label      text     No             No       Yes (default)`

= Which post types are indexed by default? How do I modify which post types are indexed? =
By default, posts and pages are indexed. To modify this, use the `lift_indexed_post_types` filter which is an array of post types to index. 

== Screenshots ==

1. Lift setup
2. Lift status dashboard
3. Lift search form

== Changelog ==

= 1.1 =
* UI: `lift_search_form()` now duplicates the standard `get_search_form()`
markup to play nicer with themes.
* UI: Show the filtered term as the dropdown labels for filters and highlight.
Clean up terms on filter labels. Make Relevancy the default sorting.
* UI: Filters now work when more than one search form is present in a page.
* Refactor: rename filters. `lift_default_fields` to `lift_filters_default_fields`, `lift-form-field-objects` to `lift_filters_form_field_objects`, `lift_form_html` to `lift_search_form`
* Refactor: `Cloud_Config` class to be independent.
* Refactor: Calls to `Cloud_Config_Request::__make_request()` can now override key
flattening.


= 1.0.1 =
* Fix CloudSearch capitalization.
* Refactor error logging.

= 1.0 =
* Initial release.