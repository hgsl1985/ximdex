<?php
// 0. This class converts strings from the csv and the json arrays so they can be used as numbers.
class Number_handle
{
    function convert_value($input_value,$type,$rem_chars)
    {
        if($type == "COST"){
            $output_value = str_replace($rem_chars, "", $input_value);
            // we convert the decimal symbol so it's operable
            $output_value = str_replace(",", ".", $output_value);
            $output_value = floatval($output_value);
        }elseif($type == "QUANTITY"){
            $output_value = str_replace($rem_chars, "", $input_value);
            // we convert the decimal symbol so it's operable
            $output_value = str_replace(",", ".", $output_value);
            $output_value = intval($output_value);
        }else{ // there are no other allowed inputs
            $output_value = "ERROR";
        }
        return $output_value;
    }
    function handle_json($input_value)
    {
        $isneg = FALSE; // stores if the value is negative
        $isdec = FALSE; // stores if the value has decimals
        $prevnum = FALSE; // stores if the previous character was a number
        $firstnumber = 0; // stores the position of the first character that was a cypher
        $conc = ""; // stores the concatenation of cyphers 
        
        // We substitute the "€" symbol because it's not processed properly by the for loop
        $input_value = str_replace("€", "E", $input_value);

        $output = array();
        for($f=0;$f<strlen($input_value);$f++){
            if($input_value[$f] == "-"){
                $isneg = TRUE;
            }elseif(is_numeric($input_value[$f]) && !$prevnum){
                $firstnumber = $f;
                $prevnum = TRUE;
            }elseif($input_value[$f] == "."){
                $isdec = TRUE;
            }elseif($input_value[$f] == "E"){
                for($g=$firstnumber;$g<$f;$g++){
                    $conc .= $input_value[$g];
                    if($isdec){
                        $output[0] = floatval($conc);
                    }else{ // !$isdec
                        $output[0] = intval($conc);
                    }
                }
                if($isneg){
                   $output[0] = -1 * abs($output[0]);
                }
                $prevnum = FALSE;
                $conc = 0;
            }elseif($input_value[$f] == "%"){
                for($h=$firstnumber;$h<$f;$h++){
                    $conc .= $input_value[$h];
                    if($isdec){
                        $output[1] = floatval($conc);
                    }else{ // !$isdec
                        $output[1] = intval($conc);
                    }
                }
                if($isneg){
                    $output[1] = -1 * abs($output[1]);
                }
                $prevnum = FALSE;
                $isdec = FALSE;
                $conc = 0;
            } // else: if the current character is a decimal point or a cypher different than the first of a number, we skip it
        }
        return $output;
    }
}

// 1. We recover the arguments given through the console with the csv and json files location.
$path_csv = $argv[1];
$path_json = $argv[2];

// 2. We import the csv file into an array ($csv_table).
$csv_row = 0;
if(($csv_handle = fopen($path_csv, "r"))) {
    while(($csv_data = fgetcsv($csv_handle, 1000, ";"))) {
        $csv_fields = count($csv_data);
        for($c=0; $c < $csv_fields; $c++) {
            if($csv_row == 0){
                $csv_keys[$c] = $csv_data[$c];
            }else{ // $csv_row != 0
                $csv_table[$csv_row][$csv_keys[$c]] = $csv_data[$c];
            }
        }
        $csv_row++;
    }
    fclose($csv_handle);
}

// 2.5 We process the values coming from the csv so they can be used as numbers.
foreach($csv_table as $current_row => $current_pair){
    foreach($current_pair as $current_key => $current_value){
        if($current_key == "COST" OR $current_key == "QUANTITY"){
            $chars = array("€", "$", ".");
            $conversion = new Number_handle();
            $converted_value = $conversion->convert_value($current_value,$current_key,$chars);
            $processed_table[$current_row][$current_key] = $converted_value;
        }
        if($current_key == "CATEGORY")
            $processed_table[$current_row][$current_key] = $current_value;
    }
}

// 3. We import the json file into an array ($json_table).
$json_handle = file_get_contents($path_json);
$json_table = json_decode($json_handle, TRUE);

// 3.5 We process the values coming from the json so they can be used as numbers.
foreach($json_table["categories"] as $present_key => $present_value){
    if($present_key == "*") {
        $present_key = "misc";
    }
    $handle = new Number_handle();
    $handled_values[$present_key] = $handle->handle_json($present_value);
}

// Si no pasamos por SQL
$calc_table = $processed_table;

// 4. We calculate the sales for each category.
foreach($calc_table as $this_row => $this_pair){
    $count = 0;
    foreach($this_pair as $this_key => $this_value){
        if(is_string($this_value)){
            $sales_table[$this_row][$this_key] = $this_value;
            $count++;
        }elseif(is_int($this_value)){
            $this_quantity = $this_value;
            $count++;
        }else{ // is_float
            $this_cost = $this_value;
            $count++;
        }
        if($count == 3){ // we store in the array only when we've got all the data
            $sales_table[$this_row]["SALES"] = $this_cost * $this_quantity;
        }
    }
}

$profits_table = array();
// 4.5 We calculate the profits for each category.
foreach($sales_table as $this_row => $this_pair){
    foreach($this_pair as $this_key => $this_value){
        if($this_key == "CATEGORY"){
            $this_category = $this_value;
        }else{ // $this_key == "SALES"
            $this_sale = $this_value;
        }
    }
    if(in_array($this_category, array_keys($handled_values))){
        if($handled_values[$this_category][0]){ // [0] = €
            $profits_table[$this_category][1] += $calc_table[$this_row]["QUANTITY"]
                                               * $handled_values[$this_category][0];
        }
        if($handled_values[$this_category][1]){ // [1] = %
            $profits_table[$this_category][1] += $sales_table[$this_row][$this_key]
                                               * $handled_values[$this_category][1] / 100;
        }
    }else{ // the category given in the csv is not in the json, so we use the "*" (misc)
        if($handled_values["misc"][0]){ // [0] = €
            $profits_table[$this_category][1] += $calc_table[$this_row]["QUANTITY"]
                                               * $handled_values["misc"][0];
        }
        if($handled_values["misc"][1]){ // [1] = %
            $profits_table[$this_category][1] += $sales_table[$this_row][$this_key]
                                               * $handled_values["misc"][1] / 100;
        }
    }
}

// 5. We print the list of categories and profits.
echo "****************************" . "\xA";
echo "* Category" . "\t" . "Profit     *" . "\xA";
echo "****************************" . "\xA";
foreach($profits_table as $category => $pair){
    foreach($pair as $row => $profit){
        if(strlen($category) > 5) { // only important for presentation
            if(strlen($profit) > 6){ // only important for presentation
                echo "* " . $category . "\t" . number_format($profit, 2, ".", "") . "   *" . "\xA";
            }else{ // (strlen($profit) <= 6
                echo "* " . $category . "\t" . number_format($profit, 2, ".", "") . "\t" . "   *" . "\xA";
            }
        }else{ // strlen($category) <= 5
            if(strlen($profit) > 6){ // only important for presentation
                echo "* " . $category . "\t" . "\t" . number_format($profit, 2, ".", "") . " *" . "\xA";
            }else{ // (strlen($profit) <= 6
                echo "* " . $category . "\t" . "\t" . number_format($profit, 2, ".", "") . "\t" . "   *" . "\xA";
            }
        }
    }
}
echo "****************************" . "\xA";

?>