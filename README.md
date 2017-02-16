# eml2sparkpost
Simple command line utility, written in PHP script.
External dependencies are described in source code comments.

Usage is shown if you run the command with no arguments.

To set up API key: create a file named sparkpost.ini as follows

```INI
[SparkPost]
Authorization = "your api key string"
```
For SparkPost Enterprise, you can set the Host using the INI file setting, e.g.

```INI
[SparkPost]
Authorization = "your api key string"
Host = "yourdomain.sparkpostelite.com"
Return-Path = "bounces@yourdomain.com"
Binding = "outbound"```