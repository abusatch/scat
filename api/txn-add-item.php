<?
include '../scat.php';

$details= array();

$id= (int)$_REQUEST['id'];

$search= $_REQUEST['q'];

if (!$search && !$id) die_jsonp('no query specified');

$details['txn']= $_REQUEST['txn'];
if (!$details['txn']) {

  // if there's a transaction with no items yet, hijack it
  $q= "SELECT txn.id AS txn,
              CONCAT('Sale ', DATE_FORMAT(NOW(), '%Y'), '-', number)
                AS description,
              created,
              tax_rate
         FROM txn LEFT JOIN txn_line ON (txn.id = txn)
         WHERE txn_line.id IS NULL AND type = 'customer'";
  $r= $db->query($q);
  if (!$r) {
    die(json_encode(array('error' => 'Query failed. ' . $db->error,
                          'query' => $q)));
  }

  if ($r->num_rows) {
    $details= $r->fetch_assoc();

  } else {

    $q= "START TRANSACTION;";
    $r= $db->query($q);
    if (!$r) {
      die(json_encode(array('error' => 'Query failed. ' . $db->error,
                            'query' => $q)));
    }

    $q= "SELECT 1 + MAX(number) AS number FROM txn WHERE type = 'customer'";
    $r= $db->query($q);
    if (!$r) {
      die(json_encode(array('error' => 'Query failed. ' . $db->error,
                            'query' => $q)));
    }
    $row= $r->fetch_assoc();

    $q= "INSERT INTO txn
            SET created= NOW(),
                type = 'customer',
                number = $row[number],
                tax_rate = " . DEFAULT_TAX_RATE;
    $r= $db->query($q);
    if (!$r) {
      die(json_encode(array('error' => 'Query failed. ' . $db->error,
                            'query' => $q)));
    }

    $q= "SELECT id AS txn,
                CONCAT('Sale ', DATE_FORMAT(NOW(), '%Y'), '-', number)
                  AS description,
                created,
                tax_rate
           FROM txn WHERE id = " . $db->insert_id;
    $r= $db->query($q);
    if (!$r) {
      die(json_encode(array('error' => 'Query failed. ' . $db->error,
                            'query' => $q)));
    }
    $details= $r->fetch_assoc();

    $r= $db->commit();
    if (!$r) {
      die(json_encode(array('error' => 'Query failed. ' . $db->error,
                            'query' => $q)));
    }

  }
}

$criteria= array();

if ($search) {
  $terms= preg_split('/\s+/', $search);
  foreach ($terms as $term) {
    $term= $db->real_escape_string($term);
    if (preg_match('/^code:(.+)/i', $term, $dbt)) {
      $criteria[]= "(item.code LIKE '{$dbt[1]}%')";
    } else {
      $criteria[]= "(item.name LIKE '%$term%'
                 OR brand.name LIKE '%$term%'
                 OR item.code LIKE '%$term%'
                 OR barcode.code LIKE '%$term%')";
    }
  }
} else {
  $criteria[]= "(item.id = $id)";
}

# allow option to include inactive and/or deleted
if (!$_REQUEST['all']) {
  $criteria[]= "(active AND NOT deleted)";
} else {
  $criteria[]= "(NOT deleted)";
}

$q= "SELECT
            item.id id,
            item.code code,
            item.name name,
            brand.name brand,
            retail_price msrp,
            CASE discount_type
              WHEN 'percentage' THEN
                ROUND_TO_EVEN(retail_price * ((100 - discount) / 100), 2)
              WHEN 'relative' THEN (retail_price - discount) 
              WHEN 'fixed' THEN (discount)
              ELSE retail_price
            END price,
            IFNULL(CONCAT('MSRP $', retail_price, ' / Sale: ',
                          CASE discount_type
              WHEN 'percentage' THEN CONCAT(ROUND(discount), '% off')
              WHEN 'relative' THEN CONCAT('$', discount, ' off')
            END), '') discount,
            (SELECT SUM(allocated) FROM txn_line WHERE item = item.id) stock,
            barcode.quantity quantity
       FROM item
  LEFT JOIN brand ON (item.brand = brand.id)
  LEFT JOIN barcode ON (item.id = barcode.item)
      WHERE " . join(' AND ', $criteria) . "
   GROUP BY item.id
      LIMIT 10";

$r= $db->query($q);
if (!$r) {
  die(json_encode(array('error' => 'Query failed. ' . $db->error,
                        'query' => $q)));
}

$items= array();
while ($row= $r->fetch_assoc()) {
  /* force numeric values to numeric type */
  $row['msrp']= (float)$row['msrp'];
  $row['price']= (float)$row['price'];
  $row['stock']= (int)$row['stock'];
  $row['quantity']= (int)$row['quantity'];
  $items[]= $row;
}

/* if it is just one item, go ahead and add it to the invoice */
if (count($items) == 1) {
  $q= "INSERT INTO txn_line (txn, item, ordered, line,
                             retail_price, discount, discount_type)
       SELECT $details[txn] AS txn, {$items[0]['id']} AS item, -1 AS ordered,
              IFNULL((SELECT MAX(line) + 1 FROM txn_line
                       WHERE txn = $details[txn]), 1) AS line,
              retail_price, discount, discount_type
         FROM item WHERE id = {$items[0]['id']}";
  $r= $db->query($q);
  if (!$r) {
    die(json_encode(array('error' => 'Query failed. ' . $db->error,
                          'query' => $q)));
  }
  $items[0]['line_id']= $db->insert_id;
}

echo json_encode(array('details' => $details, 'items' => $items));
