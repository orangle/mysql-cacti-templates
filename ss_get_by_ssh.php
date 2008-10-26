<?php

# ============================================================================
# This is a script to retrieve information over SSH for a Cacti graphing
# process.
#
# This program is copyright (c) 2008 Baron Schwartz. Feedback and improvements
# are welcome.
#
# THIS PROGRAM IS PROVIDED "AS IS" AND WITHOUT ANY EXPRESS OR IMPLIED
# WARRANTIES, INCLUDING, WITHOUT LIMITATION, THE IMPLIED WARRANTIES OF
# MERCHANTIBILITY AND FITNESS FOR A PARTICULAR PURPOSE.
#
# This program is free software; you can redistribute it and/or modify it under
# the terms of the GNU General Public License as published by the Free Software
# Foundation, version 2.
#
# You should have received a copy of the GNU General Public License along with
# this program; if not, write to the Free Software Foundation, Inc., 59 Temple
# Place, Suite 330, Boston, MA  02111-1307  USA.
# ============================================================================

# ============================================================================
# Define parameters.
# ============================================================================
$ssh_user = 'cacti';                      # SSH username
$ssh_port = 22;                           # SSH port
$ssh_iden = '-i /var/www/cacti/.ssh/id_rsa';  # SSH identity
$cache_dir  = '';  # If set, this uses caching to avoid multiple calls.
$poll_time  = 300;     # Adjust to match your polling interval.

# ============================================================================
# Parameters for specific graphs
# ============================================================================
$url        = '/server-status';           # Where Apache status lives

# ============================================================================
# You should not need to change anything below this line.
# ============================================================================

# ============================================================================
# Define whether you want debugging behavior.
# ============================================================================
$debug = TRUE;
error_reporting($debug ? E_ALL : E_ERROR);

# Make this a happy little script even when there are errors.
$no_http_headers = true;
ini_set('implicit_flush', false); # No output, ever.
ob_start(); # Catch all output such as notices of undefined array indexes.
function error_handler($errno, $errstr, $errfile, $errline) {
   print("$errstr at $errfile line $errline\n");
}
# ============================================================================
# Set up the stuff we need to be called by the script server.
# ============================================================================
if ( file_exists( dirname(__FILE__) . "/../include/global.php") ) {
   # See issue 5 for the reasoning behind this.
   include_once(dirname(__FILE__) . "/../include/global.php");
}
elseif ( file_exists( dirname(__FILE__) . "/../include/config.php" ) ) {
   # Some versions don't have global.php.
   include_once(dirname(__FILE__) . "/../include/config.php");
}

# ============================================================================
# Make sure we can also be called as a script.
# ============================================================================
if (!isset($called_by_script_server)) {
   array_shift($_SERVER["argv"]); # Strip off ss_get_by_ssh.php
   $options = parse_cmdline($_SERVER["argv"]);
   validate_options($options);
   $result = ss_get_by_ssh($options);
   if ( !$debug ) {
      # Throw away the buffer, which ought to contain only errors.
      ob_end_clean();
   }
   else {
      ob_end_flush(); # In debugging mode, print out the errors.
   }

   # Split the result up and extract only the desired parts of it.
   $wanted = explode(',', $options['items']);
   $output = array();
   foreach ( explode(' ', $result) as $item ) {
      if ( in_array(substr($item, 0, 2), $wanted) ) {
         $output[] = $item;
      }
   }
   print(implode(' ', $output));
}

# ============================================================================
# Work around the lack of array_change_key_case in older PHP.
# ============================================================================
if ( !function_exists('array_change_key_case') ) {
   function array_change_key_case($arr) {
      $res = array();
      foreach ( $arr as $key => $val ) {
         $res[strtolower($key)] = $val;
      }
      return $res;
   }
}

# ============================================================================
# Validate that the command-line options are here and correct
# ============================================================================
function validate_options($options) {
   $opts = array('host', 'port', 'items', 'url', 'nocache');
   # Required command-line options
   foreach ( array('host', 'items') as $option ) {
      if ( !isset($options[$option]) || !$options[$option] ) {
         usage("Required option --$option is missing");
      }
   }
   foreach ( $options as $key => $val ) {
      if ( !in_array($key, $opts) ) {
         usage("Unknown option --$key");
      }
   }
}

# ============================================================================
# Print out a brief usage summary
# ============================================================================
function usage($message) {
   $usage = <<<EOF
$message
Usage: php ss_get_by_ssh.php --host <host> --items <item,...> [OPTION]

   --host      Hostname to connect to
   --port      Port to connect to
   --items     Comma-separated list of the items whose data you want
   --url       The url, such as /server-status, where Apache status lives
   --nocache   Do not cache results in a file

EOF;
   die($usage);
}

# ============================================================================
# Parse command-line arguments, in the format --arg value --arg value, and
# return them as an array ( arg => value )
# ============================================================================
function parse_cmdline( $args ) {
   $result = array();
   $cur_arg = '';
   foreach ($args as $val) {
      if ( strpos($val, '--') === 0 ) {
         if ( strpos($val, '--no') === 0 ) {
            # It's an option without an argument, but it's a --nosomething so
            # it's OK.
            $result[substr($val, 2)] = 1;
            $cur_arg = '';
         }
         elseif ( $cur_arg ) { # Maybe the last --arg was an option with no arg
            if ( $cur_arg == '--port' ) {
               # Special case because Cacti will pass these without an arg
               $cur_arg = '';
            }
            else {
               die("Missing argument to $cur_arg\n");
            }
         }
         else {
            $cur_arg = $val;
         }
      }
      else {
         $result[substr($cur_arg, 2)] = $val;
         $cur_arg = '';
      }
   }
   if ( $cur_arg ) {
      die("Missing argument to $cur_arg\n");
   }
   return $result;
}

# ============================================================================
# This is the main function.  Some parameters are filled in from defaults at the
# top of this file.
# ============================================================================
function ss_get_by_ssh( $options ) {
   global $debug, $ssh_user, $ssh_port, $ssh_iden, $url, $cache_dir, $poll_time;

   # TODO: genericize
   $cache_file = "$cache_dir/$options[host]-apache_cacti_stats.txt";

   # First, check the cache.
   $fp = null;
   if ( !isset($options['nocache']) && $cache_dir ) {
      if ( file_exists($cache_file) && filesize($cache_file) > 0
         && filectime($cache_file) + ($poll_time/2) > time() )
      {
         # The file is fresh enough to use.
         $arr = file($cache_file);
         # The file ought to have some contents in it!  But just in case it
         # doesn't... (see issue #6).
         if ( count($arr) ) {
            # TODO: release the lock
            return $arr[0];
         }
         else {
            if ( $debug ) {
               trigger_error("The function file($cache_file) returned nothing!\n");
            }
         }
      }
      if ( !$fp = fopen($cache_file, 'w+') ) {
         die("Cannot open file '$cache_file'");
      }
   }

   # SSH to the server and get the data.
   $user = $ssh_user;
   $port = isset($options['port']) ? $options['port'] : $ssh_port;
   $iden = $ssh_iden;
   $cmd = "ssh $user@$options[host] -p $port $iden ";

   $type = 'apache'; # TODO
   $result = array();
   switch ( $type ) {
   case 'apache':
      $result = get_stats_apache($cmd);
      break;
   }

   # Define the variables to output.  I use shortened variable names so maybe
   # it'll all fit in 1024 bytes for Cactid and Spine's benefit.  This list must
   # come right after the word MAGIC_VARS_DEFINITIONS.  The Perl script parses
   # it and uses it as a Perl variable.
   $keys = array(
      'Requests'               => 'a0',
      'Bytes_sent'             => 'a1',
      'Idle_workers'           => 'a2',
      'Busy_workers'           => 'a3',
      'CPU_Load'               => 'a4',
      'Waiting_for_connection' => 'a5',
      'Starting_up'            => 'a6',
      'Reading_request'        => 'a7',
      'Sending_reply'          => 'a8',
      'Keepalive'              => 'a9',
      'DNS_lookup'             => 'aa',
      'Closing_connection'     => 'ab',
      'Logging'                => 'ac',
      'Gracefully_finishing'   => 'ad',
      'Idle_cleanup'           => 'ae',
      'Open_slot'              => 'af',
   );

   # Return the output.
   $output = array();
   foreach ($keys as $key => $short ) {
      $val      = isset($result[$key]) ? $result[$key] : 0;
      $output[] = "$short:$val";
   }
   $result = implode(' ', $output);
   if ( $fp ) {
      if ( fwrite($fp, $result) === FALSE ) {
         die("Cannot write to '$cache_file'");
         # TODO: then truncate file, too
      }
      fclose($fp);
   }
   return $result;
}

function get_stats_apache ( $cmd ) {
   $url = '/server-status'; # TODO
   # TODO: allow --http-user --http-password
   $cmd = "$cmd wget -U Cacti/1.0 -q -O - -T 5 'http://localhost$url?auto'";
   $str = `$cmd`;

   $result = array(
      'Requests'     => 0,
      'Bytes_sent'   => 0,
      'Idle_workers' => 0,
      'Busy_workers' => 0,
      'CPU_Load'     => 0,
      # More are added from $scoreboard below.
   );

   # Mapping from Scoreboard statuses to friendly labels
   $scoreboard = array(
      '_' => 'Waiting_for_connection',
      'S' => 'Starting_up',
      'R' => 'Reading_request',
      'W' => 'Sending_reply',
      'K' => 'Keepalive',
      'D' => 'DNS_lookup',
      'C' => 'Closing_connection',
      'L' => 'Logging',
      'G' => 'Gracefully_finishing',
      'I' => 'Idle_cleanup',
      '.' => 'Open_slot',
   );
   foreach ( $scoreboard as $key => $val ) {
      $result[$val] = 0;
   }

   # Mapping from line prefix to data item name
   $mapping = array (
      "Total Accesses" => 'Requests',
      "Total kBytes"   => 'Bytes_sent',
      "CPULoad"        => 'CPU_Load',
      "BusyWorkers"    => 'Busy_workers',
      "IdleWorkers"    => 'Idle_workers',
   );

   foreach ( explode("\n", $str) as $line ) {
      $words = explode(": ", $line);
      if ( array_key_exists($words[0], $mapping) ) {
         # Check for really small values indistinguishable from 0, but otherwise
         # just copy the value to the output.
         $result[$mapping[$words[0]]] = strstr($words[1], 'e') ? 0 : $words[1];
      }
      elseif ( $words[0] == "Scoreboard" ) {
         $string = $words[1];
         $length = strlen($string);
         for ( $i = 0; $i < $length ; $i++ ) {
            $result[$scoreboard[$string[$i]]]++;
         }
		}
   }
   return $result;
}

# ============================================================================
# Extracts the numbers from a string.  You can't reliably do this by casting to
# an int, because numbers that are bigger than PHP's int (varies by platform)
# will be truncated.  So this just handles them as a string instead.
# ============================================================================
function tonum ( $str ) {
   global $debug;
   preg_match('{(\d+)}', $str, $m); 
   if ( isset($m[1]) ) {
      return $m[1];
   }
   elseif ( $debug ) {
      print_r(debug_backtrace());
   }
   else {
      return 0;
   }
}

?>