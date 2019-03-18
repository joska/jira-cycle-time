# jira-cycle-time

## Description
This utility calculates "cycle time" in working hours for those teams with backlogs containing PBIs that are open for days and weeks. It calculates the real number of working hours based on the settings in workHoursDifference() method, subtracting weekends.

The output is the CSV of the processed issues and some basic stats.


## How to use
- edit settings in beginning
- change URLs to your own JIRA installation (cloud or on-premise)
- change login credentials to working ones (look for e-mail addresses)
- Run in console using `php index.php`.

## Disclaimer
This piece of somewhat crappy and unrefactored code is provided as it is without any somewhat what so ever. You are free to use it as you wish and modify it. It served me once, hope it will serve you as well.