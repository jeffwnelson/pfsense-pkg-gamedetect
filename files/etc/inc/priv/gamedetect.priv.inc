<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
global $priv_list;

$priv_list['page-services-gamedetect'] = array();
$priv_list['page-services-gamedetect']['name'] = "WebCfg - Services: Game Detect";
$priv_list['page-services-gamedetect']['descr'] = "Allow access to the Game Detect settings page.";
$priv_list['page-services-gamedetect']['match'] = array();
$priv_list['page-services-gamedetect']['match'][] = "pkg_edit.php?xml=gamedetect.xml*";

$priv_list['page-status-gamedetect'] = array();
$priv_list['page-status-gamedetect']['name'] = "WebCfg - Status: Game Detect";
$priv_list['page-status-gamedetect']['descr'] = "Allow access to the Game Detect status page.";
$priv_list['page-status-gamedetect']['match'] = array();
$priv_list['page-status-gamedetect']['match'][] = "status_gamedetect.php*";
