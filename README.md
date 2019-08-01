# BerlinDB

BerlinDB is a collection of PHP classes and functions that aims to provide an <a href="https://en.wikipedia.org/wiki/Object-relational_mapping">ORM</a>-like experience and interface to WordPress database tables.

This repository contains all of the code that is required to be included in your WordPress project.

The most common use-case for BerlinDB is a WordPress Plugin that needs to create custom database tables, but more advanced uses are possible, including managing and interfacing with the WordPress Core database tables themselves.

Future repositories in this organization will contain examples, extensions, drop-ins, unit tests, and more.

----

The name of this project comes from WordCamp Europe 2019, where it was <a href="https://jjj.blog/wceu-2019/">originally announced</a> as an unnamed library. Thank you to <a href="https://peterwilson.cc">Peter Wilson</a> for the idea to pay homage to such a wonderful audience.

----

The code in this repository represents the cumulative effort of dozens of individuals across multiple projects, spanning multiple continents, native languages, and years of conceptual development:

* Easy Digital Downloads (<a href="https://github.com/easydigitaldownloads/easy-digital-downloads/tree/release/3.0">3.0 and higher</a>)
* Sugar Calendar (<a href="https://github.com/sugarcalendar/sugar-event-calendar-lite">2.0 and higher</a>)
* Restrict Content Pro (<a href="https://github.com/restrictcontentpro">3.1 and higher</a>)
* WordPress Multisite (<a href="https://make.wordpress.org/core/components/networks-sites/">inspired by</a>)
* BuddyPress (<a href="https://buddypress.org">inspired by</a>)

These projects all require custom database tables to acheive their goals (and to meet the expecations that their users have in them) to perform and scale flawlessly in a highly available WordPress based web application.

Each of these projects originally implemented their own bespoke approaches to database management, resulting in a massive amount of code duplication, rework, and eventual fragmentation of approaches and ideas.

This project helps avoid those issues by (somewhat magically) limiting how much code you need to write to accomplish the same repetitive database related tasks.

----

This organization was created by (and is managed by) <a href="https://sandhillsdev.com">Sandhills Development, LLC</a>, where we aim to craft superior experiences through ingenuity, with <a href="https://sandhillsdev.com/commitments/">deep commitment</a> to (and appreciation for) the human element.
