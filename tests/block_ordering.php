<?php
function blocksort($items) {
	// sort blocks randomly (if they are consecutive), then by item number and if the latter are identical, randomly
	$block_order = $item_order = $random_order = $block_numbers = $item_ids =  array();
	$last_block = "";
	$block_nr = 0;
	foreach($items AS $item) {
		if($item['block_order'] == "") { // not blocked
			$block_order[] = $block_nr;
		} else {
			if($last_block === "") { // new segment of blocks
				$block_nr = $block_nr + 1000001;
			}
			if(! array_key_exists($item['block_order'], $block_numbers)) { // new block
				// by choosing this range, the next non-block segment is forced to follow
				$rand_block_number = mt_rand($block_nr-999999,$block_nr-1);

				while(in_array($rand_block_number,$block_numbers)) { // don't allow collisions. possible infinite loop, but we don't allow that many items, let alone blocks
					$rand_block_number = mt_rand($block_nr-999999,$block_nr-1);
				}
				$block_numbers[ $item['block_order'] ] = $rand_block_number;
			}
			$block_order[] = $block_numbers[ $item['block_order'] ]; // get stored block order
		} // sort the blocks with each other

		// but keep the order within blocks if desired
		$item_order[] = $item['item_order']; // after sorting by block, sort by item order 
		$random_order[] = mt_rand();		 // if item order is identical, sort randomly (within block)
		$item_ids[] = $item['id'];
		$last_block = $item['block_order'];
	}
	print_r($block_numbers);
	print_r($block_order);
	array_multisort($block_order, $item_order, $random_order, $item_ids);
	print_r($item_ids);
}

blocksort( array(
	array("block_order" => "", "item_order" => 1, "id" => "xstart1"),
	array("block_order" => "", "item_order" => 1, "id" => "xstart2"),
	array("block_order" => "B", "item_order" => 1, "id" => "xB1"),
	array("block_order" => "B", "item_order" => 1, "id" => "xB2"),
	array("block_order" => "B", "item_order" => 2, "id" => "xB3"),
	array("block_order" => "C", "item_order" => 1, "id" => "xC1"),
	array("block_order" => "C", "item_order" => 1, "id" => "xC2"),
	array("block_order" => "", "item_order" => 5, "id" => "xend"),
	
) );

echo "
---------
";
blocksort( array(
	array("block_order" => "A", "item_order" => 1, "id" => "xA1"),
	array("block_order" => "A", "item_order" => 2, "id" => "xA2"),
	array("block_order" => "B", "item_order" => 1, "id" => "xB1"),
	array("block_order" => "B", "item_order" => 1, "id" => "xB2"),
	array("block_order" => "B", "item_order" => 2, "id" => "xB3"),
	array("block_order" => "C", "item_order" => 1, "id" => "xC1"),
	array("block_order" => "C", "item_order" => 1, "id" => "xC2"),
	array("block_order" => "C", "item_order" => 1, "id" => "xC3"),
	
));
echo "
---------
";

blocksort( array(
	array("block_order" => "A", "item_order" => 1, "id" => "A"),
	array("block_order" => "B", "item_order" => 1, "id" => "B"),
	array("block_order" => "C", "item_order" => 1, "id" => "C"),
	array("block_order" => "D", "item_order" => 1, "id" => "D"),
	array("block_order" => "E", "item_order" => 1, "id" => "E"),
	array("block_order" => "F", "item_order" => 1, "id" => "F"),
	array("block_order" => "G", "item_order" => 1, "id" => "G"),
	array("block_order" => "H", "item_order" => 1, "id" => "H"),
));
echo "
---------
";
blocksort( array(
	array("block_order" => "", "item_order" => 1, "id" => "x1"),
	array("block_order" => "", "item_order" => 2, "id" => "x2"),
	array("block_order" => "", "item_order" => 3, "id" => "x3"),
	array("block_order" => "", "item_order" => 4, "id" => "x4"),
	array("block_order" => "", "item_order" => 5, "id" => "x5"),
	array("block_order" => "", "item_order" => 6, "id" => "x6"),
	array("block_order" => "", "item_order" => 7, "id" => "x7"),
	array("block_order" => "", "item_order" => 8, "id" => "x8"),
));
echo "
---------
";
blocksort( array(
	array("block_order" => "", "item_order" => 1, "id" => "x1"),
	array("block_order" => "", "item_order" => 1, "id" => "x2"),
	array("block_order" => "", "item_order" => 1, "id" => "x3"),
	array("block_order" => "", "item_order" => 1, "id" => "x4"),
	array("block_order" => "", "item_order" => 1, "id" => "x5"),
	array("block_order" => "", "item_order" => 1, "id" => "x6"),
	array("block_order" => "", "item_order" => 1, "id" => "x7"),
	array("block_order" => "", "item_order" => 1, "id" => "x8"),
));
echo "
---------
";

blocksort( array(
	array("block_order" => "A", "item_order" => 1, "id" => "xA1"),
	array("block_order" => "A", "item_order" => 1, "id" => "xA2"),
	array("block_order" => "B", "item_order" => 1, "id" => "xB1"),
	array("block_order" => "B", "item_order" => 1, "id" => "xB2"),
	array("block_order" => "", "item_order" => 1, "id" => "xnoblock"),
	array("block_order" => "*", "item_order" => 1, "id" => "x*"),
	array("block_order" => "*", "item_order" => 1, "id" => "x*"),
	array("block_order" => "&", "item_order" => 1, "id" => "x&"),
	array("block_order" => "&", "item_order" => 1, "id" => "x&"),
));
