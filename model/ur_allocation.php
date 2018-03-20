<?php

//This file reads file with university allocations and create a .json out of it so it can be consumed by the js code :)

$ur_file_uri = './ur_allocation.csv';

if (($ur_file = fopen($ur_file_uri, 'r')) === false) {
    die('Error opening UR Allocation File');
}

$headers = fgetcsv($ur_file);
$complete = array();

while ($row = fgetcsv($ur_file)) {
    $complete[] = array_combine($headers, $row);
}

fclose($ur_file);

echo json_encode($complete);