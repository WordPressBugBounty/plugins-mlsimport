=== MLSImport - Download and synchronize real estate data from various MLS (Multiple Listing Services) ===
Contributors: mlsimport
Tags: Multiple Listing Services, MLS Integration, MLS, Real Estate, Real Estate properties, MLS Data Download, Real Estate Data, MLS Integration, Property Listings, Data Synchronization, MLS Connectivity, Real Estate Software,  Real Estate Listings, MLS API, Data Import, Real Estate Solutions, Property Search, Real Estate Agents, MLS Access,  MLS Automation, Property Database, MLS Services, Real Estate Professionals, MLS Plugin
Requires at least: 5.2
Tested up to: 6.7.1
Requires PHP: 7.4
Stable Tag: 6.1.10
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
MlsImport is an MLS Wordpress plugin that lets you download and synchronize real estate data from various MLSs (Multiple Listing Services). It works by calling  MLSimport API/your MLS API, reading the data from your Multiple Listing Association, and transforming it into a WordPress-like format. You will need a valid access token from your MLS to use this plugin and an MLSImport account. Currently, the plugin only works for <a href="https://wpresidence.net/" target="_blank">WPResidence</a>, Houzes, Real Homes, and <a href="https://wpestate.org/" target="_blank">WpEstate Wordpress Themes</a>. 

If you are the owner of a real estate theme and want to be integrated with MLSimport, feel free to contact us
== Description ==

[youtube https://www.youtube.com/watch?v=3WSb8BDA07c]

Connect your real estate website with <a href="https://mlsimport.com/" target="_blank">MLSImport.com plugin</a> to effortlessly sync, import, and manage MLS property listings, providing your users with up-to-date and accurate real estate information directly on your site. 

<h3>Built on the latest RESO API Technology</h3>

The MLS Import plugin provides an up-to-date method for linking your website to the MLS Reso Web API server. You can leave behind the old IDX solutions that rely on RETS, as they are no longer effective or relevant.

<a href="https://mlsimport.com/reso-standard-explained-in-detail/" target="_blank">RESO Standard Explained in Detail</a> 


<h3>Direct Integration</h3>

Our MLS solution smoothly brings your chosen properties directly into your WordPress website. Additionally, it optimizes performance by serving images from a content delivery network (CDN).

<h3>Sync Listings Automatically</h3>

MLS Import regularly updates your published property listings to match the most recent changes in MLS listings, ensuring that your website always displays accurate and up-to-date information.

<h3>800+ MLS Markets Coverage</h3>

We can establish connections with more than 800 MLS markets in the United States and Canada. If you don't see your specific MLS listed, please get in touch with us, and we'll gladly assist you.




== CONNECT WITH US ==
<a href="https://www.youtube.com/@mlsimport3513" target="_blank">YouTube</a>
<a href="https://www.facebook.com/MLSImport" target="_blank">Facebook</a>
<a href="https://twitter.com/MLSImport" target="_blank">Twitter</a>
<a href="https://www.linkedin.com/company/mlsimport/" target="_blank">LinkedIn</a>


== Screenshots ==


== Frequently Asked Questions ==

=What themes can I use with the MLS Import plugin?=
MLS Import works with the best Real Estate WordPress themes. The currently supported themes are <a href="https://wpresidence.net/" target="_blank">WpResidence</a>, <a href="https://wpestate.org/" target="_blank">WpEstate</a>, Houzez, and  Real Homes. More themes will be added soon.

=Will you import thousands of images?=
No, we do not import thousands of images (or any image). We ensure images are served from your MLS, so your server is not overcrowded with photos.

=Will my local MLS work with MLS Import?=
MLS Import plugin can connect and import from all RESO-ready MLS. Check this <a href="https://certification.reso.org/" target="_blank">supported list</a>. If your MLS needs to be added to the list but you would like to know if it's possible to use it, contact us to learn more.

As a member, the MLS must use his API access to use the MLS import plugin. That is because, as a solution, the MLS Import plugin imports/replicates the properties directly into the MLS member domain. 
=Is MLS Import SEO friendly?=
With MLS import, you can effectively manage your real estate website's content, which helps you create a website optimized for search engines. Additionally, you can conveniently handle and monitor all generated leads.

=How many listings can I import?=
You control how many listings you want to import and have special filters available to select which properties.


== Installation ==
1. Review our video tutorials here https://www.youtube.com/@mlsimport3513
2. Install and enable the plugin by searching for 'MlsImport' in your plugin install page
3. Signup with <a href="https://mlsimport.com/">MLSImport.com</a> and get your credentials
4. Make sure you have valid MLS access credentials
5. Under your WordPress admin's "MLS Import Settings" section, use the MLSImport.com credentials, select your MLS from the list, and add your MLS access credentials.
6. If your MLS requires "activation via ticket," open a ticket in your MLSimport.com dashboard, and we will activate it.
7. Go to the "Select Import Fields" tab and select what fields you want to import 
8. Create and publish a new 'MLS Import Task". Pick your property type, status, counties, or cities, and click start import when ready.

== Use of 3rd party services ==
This plugin will connect via REST API to mlsimport.com and https://requests.mlsimport.com to get real estate data from your MLS. It will send your connection details and reply with real estate prepared to be imported into your website. 
Read the terms and conditions of using MLSimport APIs here : https://mlsimport.com/terms-conditions/


== Changelog ==
= 6.1.4 =
Fix for ABOR on stale delete

= 6.1.2 =
Fix for Realtor.ca on property room parsing

= 6.1.1 =
* Added BuyerAgentMlsId import option
* Updated labels from MLSimport item to Import task
* Improved formatting for MLS fields using comma separation
* Enhanced messaging for starting/stopping imports
* Improved error handling


=  5.8.6 =
* Fix on importing into Houzez theme
* fix with keep true if mlsImportItemStatusDelete meta is empty
* fixed caps vs non caps on custom post meta

=  5.7 =
* Release on Wordpress plugin directory
