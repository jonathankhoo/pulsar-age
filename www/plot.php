<?php

require_once('Config.php');
require_once('Classes/DataFile.php');
require_once('Classes/Identifier.php');
require_once('Classes/CSV.php');

require_once('Classes/jpgraph/jpgraph.php');
require_once('Classes/jpgraph/jpgraph_line.php');
require_once('Classes/jpgraph/jpgraph_error.php');
require_once('Classes/jpgraph/jpgraph_scatter.php');

define('NUMBER_OF_CSV_COLUMNS', 4);
define('START_X', 140);
define('END_X', 870);

$smarty = new Smarty();
$smarty->template_dir = SMARTY_TEMPLATE_DIR;
$smarty->compile_dir = SMARTY_COMPILE_DIR;

$pulsar_name = $_REQUEST['pulsar'];
// TODO: verify pulsar name

if ($_REQUEST['x']) {
  $mouse_x = $_REQUEST['x'];
}

$m;
$b;

if ($_REQUEST['m']) {
  $m = $_REQUEST['m'];
}

if ($_REQUEST['b']) {
  $b = $_REQUEST['b'];
}

if ($_REQUEST['shh']) {
  $shh = TRUE;
} else {
  $shh = FALSE;
}

$id = Identifier::GetId();
// TODO: verify id 

// Gradient of the first period-vs-MJD plot.
$gradient;

$y_offset;

// The first period in the data set.
$period;

CreatePlotCsvFile($pulsar_name, $id, TRUE);
CreatePlotSlopeRemovedCsvFile($id, TRUE);

$csv_filename = SESSION_DIR . "period_vs_mjd_$id.csv";
$plot_filename = SESSION_DIR . "period_vs_mjd_$id.png";
CreatePeriodVsPlot($pulsar_name, $csv_filename, $plot_filename);

$csv_filename = SESSION_DIR . "period_vs_mjd_line_removed_$id.csv";
$plot_filename = SESSION_DIR . "period_vs_mjd_line_removed_$id.png";
CreatePeriodVsPlot($pulsar_name, $csv_filename, $plot_filename, FALSE);

// Global $gradient has already been calculated here.
$period_derivative_seconds = $gradient / SECONDS_IN_DAY;

// age(s) = Period(s)/(2 * P_dot(s/s)
$age_seconds =
  ($period / MILLISECONDS_IN_A_SECOND) / (2 * $period_derivative_seconds);

$age_myear =
  $age_seconds / (SECONDS_IN_DAY * 365.25 * 1000000);

if (!$shh) {
  $smarty->assign('pulsar_name', $pulsar_name);
  $smarty->assign('id', $id);
  $smarty->assign('period_derivative_days', $gradient);
  $smarty->assign('period_derivative_seconds', $period_derivative_seconds);
  $smarty->assign('pulsar_age_seconds', $age_seconds);
  $smarty->assign('pulsar_age_megayear', $age_myear);
  $smarty->assign('plot_constant', $y_offset);
  $smarty->display('plot.tpl');
}

/**
 * Reads Observations/<$pulsar name>/*.dat to extract period, period error, and MJD.
 * Creates a plot as sessions/period_vs_mjd_id.blah
 *
 * Throws any error encountered???
 */
function CreatePeriodVsPlot($pulsar_name, $csv_filename, $plot_filename, $draw_fitted_line = TRUE)
{
  // Read session/period_vs_mjd_<id>.csv.
  if (!file_exists($csv_filename)) {
    throw Error();
  }

  $fh = fopen($csv_filename, 'r');
  while (($data = fgetcsv($fh, 1000, ',')) !== FALSE) {
    $num = count($data);
    if ($num !== NUMBER_OF_CSV_COLUMNS) {
      throw Error();
    }

    $period_and_errors[] = $data[CSV_INDEX::$PERIOD];
    $period_and_errors[] = $data[CSV_INDEX::$PERIOD_ERROR];
    $MJDs[]              = $data[CSV_INDEX::$MJD];
  }

  $filename = "session/period_vs_mjd_$id.png";
  $title    = 'Period vs MJD';
  CreatePlot($period_and_errors, $MJDs, $title, $plot_filename,
    $draw_fitted_line);
}

/**
 * Creates a CSV file containing plot values (period, period error, MJD, toggle).
 * Saves the file as session/period_vs_mjd_<id>.csv.
 */
function CreatePlotCsvFile($pulsar_name, $id, $overwrite = FALSE)
{
  $csv_file = SESSION_DIR . "period_vs_mjd_$id.csv";

  if (file_exists($csv_file) && $overwrite == FALSE) {
    return;
  }

  // Extract data from each .dat file. - DataFile::GetValues(...)
  $directory = OBSERVATIONS_DIR . $pulsar_name . '/';
  $data_filenames = glob($directory . '*.dat');

  foreach ($data_filenames as $filename) {
    $data_files_contents[] = 
      DataFile::GetValues($filename);
  }

  $fp = fopen($csv_file, 'w');
  foreach ($data_files_contents as $data) {
    $fields = array(
      $data->period,
      ($data->period + $data->period_error),
      $data->MJD, 
      '1' // toggle
    );
    fputcsv($fp, $fields);
  }
  fclose($fp);
}

/**
 * Creates a CSV file containing plot values (period, period error, MJD,
 * toggle). The slope of the line is removed.
 *
 * Saves the file as session/period_vs_mjd_line_removed_<id>.csv.
 */
function CreatePlotSlopeRemovedCsvFile($id, $overwrite = FALSE)
{
  $original_csv_file = SESSION_DIR . "period_vs_mjd_$id.csv";
  $csv_file = SESSION_DIR . "period_vs_mjd_line_removed_$id.csv";

  // TODO: check if original csv file exists.
  $fh = fopen($original_csv_file, 'r');
  // Read period, period error, and MJD for each observation.
  while (($data = fgetcsv($fh, 1000, ',')) !== FALSE) {
    $num = count($data);
    if ($num !== NUMBER_OF_CSV_COLUMNS) {
      throw Error();
    }

    $periods[]       = $data[CSV_INDEX::$PERIOD];
    $period_errors[] = ($data[CSV_INDEX::$PERIOD_ERROR] -
      $data[CSV_INDEX::$PERIOD]);
    $MJDs[]          = $data[CSV_INDEX::$MJD];
  }

  fclose($fh);

  $S = 0.0;
  $Sx = 0.0;
  $Sy = 0.0;
  $Sxx = 0.0;
  $Sxy = 0.0;

  $count = count($periods);
  for ($i = 0; $i < $count; $i++) {
    $period = $periods[$i];
    $error  = $period_errors[$i];
    $mjd    = $MJDs[$i];

    $S += 1/pow($error, 2);

    $Sx += $mjd/pow($error, 2);
    $Sy += $period/pow($error, 2);

    $Sxx += ($mjd * $mjd)/pow($error, 2);
    $Sxy += ($mjd * $period) / pow($error, 2);
  }

  $delta = ($S * $Sxx) - pow($Sx, 2);
  $a = (($Sxx * $Sy) - ($Sx * $Sxy)) / $delta;
  $b = (($S * $Sxy) - ($Sx * $Sy)) / $delta;

  global $y_offset;
  $y_offset = $a;

  global $gradient;
  //$gradient = $b; // Convert from ms to s.
  $gradient = $b / MILLISECONDS_IN_A_SECOND; // Convert from ms to s.

  global $period;
  $period = $periods[0];

  // Subtract the slope from the period.
  for ($i = 0; $i < $count; $i++) {
    $y = $a + ($b * $MJDs[$i]);
    $periods[$i] -= $y;
    $periods[$i] -= $period_errors[$i] / 2;
  }

  // Write the new values into session/period_vs_mjd_line_removed_<id>.csv.
  $fp = fopen($csv_file, 'w');
  for ($i = 0; $i < $count; $i++) {
    $fields = array(
      $periods[$i],
      $periods[$i] + $period_errors[$i],
      $MJDs[$i],
      '1' // toggle
    );

    fputcsv($fp, $fields);
  }
  fclose($fp);
}

/**
 * Creates a png ($filename) using the arrays for x and y data passed.
 */
function CreatePlot($errdatay, $datax, $title, $filename, $draw_fitted_line = TRUE, $divideBy1000 = FALSE, $highlight = null)
{
  // TODO: sanity check $highlight
  if ($_REQUEST['x']) {
    $highlight = CalculateNearestElementInArray($_REQUEST['x'], $datax);
  }

  if ($divideBy1000 === TRUE) {
    // Convert all y-axis values from milliseconds to seconds.
    foreach ($errdatay as &$datay) {
      $datay /= MILLISECONDS_IN_A_SECOND;
    }
  }

  // Since jpgraph is retarded, decrease all y points by half of the 
  // corresponding error.
  $count = count($errdatay);
  for ($i = 0; $i < $count; $i += 2) {
    $datay[] = $errdatay[$i];

    $decrease_amount = ($errdatay[$i+1] - $errdatay[$i]) / 2; // period error
    $errdatay[$i] -= $decrease_amount;
    $errdatay[$i+1] -= $decrease_amount;

  }

  // #74: change time range to start from 0
  // Subtract the time of the first observation from each x value.
  $time_of_first_observation = $datax[0];

  foreach ($datax as &$x) {
    $x -= $time_of_first_observation;
  }

  $x_min = min($datax);
  $x_max = max($datax);
  $x_span = $x_max - $x_min;

  // Add 0.05 buffer.
  $x_min -= 0.02 * $x_span;
  $x_max += 0.02 * $x_span;

  $graph = new Graph(900,500);
  $graph->SetScale("linlin", 0, 0, $x_min, $x_max);

  $graph->img->SetMargin(70,70,40,40);
  $graph->SetShadow();

  $errplot = new ErrorPlot($errdatay, $datax);

  $errplot->SetColor("red");
  $errplot->SetWeight(2);
  $errplot->SetCenter();

  $sp1 = new ScatterPlot($datay, $datax);
  $sp1->mark->SetType(MARK_UTRIANGLE); 
  $graph->Add($sp1);

  $graph->title->Set($title);
  $graph->title->SetFont(FF_FONT1,FS_BOLD);
  $graph->xaxis->SetTickLabels($MJDs);
  $graph->xaxis->scale->ticks->Set(90,30);
  $graph->yaxis->HideZeroLabel();
  $graph->xaxis->HideZeroLabel();

  $graph->Add($errplot);

  global $m;
  global $b;

  if ($draw_fitted_line === TRUE && isset($m)) {
    global $y_offset;
    global $gradient;

    // Gradient and constant passed via query string.

    $x1 = $datax[0];
    $x2 = $datax[count($datax)-1];

    //$y1 = $y_offset + ($gradient * MILLISECONDS_IN_A_SECOND * $x1);
    //$y2 = $y_offset + ($gradient * MILLISECONDS_IN_A_SECOND * $x2);

    $y1 = $b + ($m * MILLISECONDS_IN_A_SECOND * $x1);
    $y2 = $b + ($m * MILLISECONDS_IN_A_SECOND * $x2);

    //$y1 = $y_offset + ($m * MILLISECONDS_IN_A_SECOND * $x1);
    //$y2 = $y_offset + ($m * MILLISECONDS_IN_A_SECOND * $x2);

    $sp1 = new ScatterPlot(array($y1, $y2), array($x1, $x2));
    $sp1->SetLinkPoints(true,'blue',2 ); 
    $sp1->mark->SetType(MARK_NONE); 
    $graph->Add($sp1);
  }

  if ($highlight != null) {
    $highlight_datay = array($errdatay[$highlight*2], $errdatay[$highlight*2 + 1]);
    $highlight_datax = array($datax[$highlight]);

    $errplot1 = new ErrorPlot($highlight_datay, $highlight_datax);
    $errplot1->SetColor("green");
    $errplot1->SetWeight(2);
    $errplot1->SetCenter();

    $graph->Add($errplot1);
  }

  $graph->Stroke($filename);
}

function CalculateNearestElementInArray($value, $array)
{
  $value -= START_X;
  $array_range = max($array) - min($array);
  $factor = $array_range / (END_X - START_X);

  $value = $value * $factor + min($array);

  $closest = abs($array[0] - $value);
  $index = 0;

  for ($i = 1; $i < count($array); $i++) {
    if (abs($array[$i] - $value) < $closest) {
      $closest = abs($array[$i] - $value);
      $index = $i;
    }
  }

  return $index;
}

?>
