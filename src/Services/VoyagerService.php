<?php
/**
 * Voyager Service class adapted from the VUFind Voyager ILS Driver
 *
 *
 * https://vufind.org/jenkins/job/VuFind/javadoc/classes/VuFind.ILS.Driver.Voyager.html
 *
 * This service class utilizes the methods that only deal with getting Bib Item Statuses
 *
 */
namespace App\Services;

use Doctrine\DBAL\Connection;

class VoyagerService
{
    /**
     * Doctrine\DBAL\Connection
     *
     * @var \Doctrine\DBAL\Connection
     */
    protected $db;

    /**
     * Name of database
     *
     * @var string
     */
    protected $dbName;


    /**
     * @var array array of configurations
     */
    protected $config = [];

    /**
     * Stored status rankings from the database; initialized to false but populated
     * by the pickStatus() method.
     *
     * @var array|bool
     */
    protected $statusRankings = false;

    /**
     * @var array array of status maps in the the following format
     *
     * array['voyager status desc'] = 'custom status desc';
     *
     */
    protected $statusMap = [];


    protected $reserveMessage = 'On Reserve';



    public function __construct(Connection $db, $voyager)
    {
        $this->db = $db;
        $this->dbName = $voyager['dbname'];
        $this->statusMap = $voyager['item_status'];
        $this->reserveMessage = $voyager['reserve_status']['message'];
    }



    /**
     * Protected support method for building sql strings.
     *
     * @param array $sql An array of keyed sql data
     *
     * @return array               An string query string and bind data
     */
    protected function buildSqlFromArray($sql)
    {
        $modifier = isset($sql['modifier']) ? $sql['modifier'] . ' ' : '';
        // Put String Together
        $sqlString = 'SELECT ' . $modifier . implode(', ', $sql['expressions']);
        $sqlString .= " FROM " . implode(", ", $sql['from']);
        $sqlString .= (!empty($sql['where']))
            ? ' WHERE ' . implode(' AND ', $sql['where']) : '';
        $sqlString .= (!empty($sql['group']))
            ? ' GROUP BY ' . implode(', ', $sql['group']) : '';
        $sqlString .= (!empty($sql['order']))
            ? ' ORDER BY ' . implode(', ', $sql['order']) : '';
        return ['string' => $sqlString, 'bind' => $sql['bind']];
    }

    /**
     * Protected support method to pick which status message to display when multiple
     * options are present.
     *
     * @param array $statusArray Array of status messages to choose from.
     *
     * @return string            The best status message to display.
     */
    protected function pickStatus($statusArray)
    {
        // Pick the first entry by default, then see if we can find a better match:
        $status = $statusArray[0];
        $rank = $this->getStatusRanking($status);
        for ($x = 1; $x < count($statusArray); $x++) {
            if ($this->getStatusRanking($statusArray[$x]) < $rank) {
                $status = $statusArray[$x];
            }
        }
        return $status;
    }

    /**
     * Support method for pickStatus() -- get the ranking value of the specified
     * status message.
     *
     * @param string $status Status message to look up
     *
     * @return int
     */
    protected function getStatusRanking($status)
    {
        // This array controls the rankings of possible status messages.  The lower
        // the ID in the ITEM_STATUS_TYPE table, the higher the priority of the
        // message.  We only need to load it once -- after that, it's cached in the
        // driver.
        if ($this->statusRankings == false) {
            // Execute SQL
            $sql = "SELECT * FROM $this->dbName.ITEM_STATUS_TYPE";
            try {
                $rows = $this->executeSQL($sql);
            } catch (PDOException $e) {
                throw new Exception($e->getMessage());
            }
            // Read results
            foreach ($rows as $row) {
                $this->statusRankings[$row['ITEM_STATUS_DESC']]
                    = $row['ITEM_STATUS_TYPE'];
            }
            if (!empty($this->config['StatusRankings'])) {
                $this->statusRankings = array_merge(
                    $this->statusRankings, $this->config['StatusRankings']
                );
            }
        }
        // We may occasionally get a status message not found in the array (i.e. the
        // "No information available" message that we hard-code when items are
        // missing); return a large number in this case to avoid an undefined index
        // error and to allow recognized statuses to take precedence.
        return isset($this->statusRankings[$status])
            ? $this->statusRankings[$status] : 32000;
    }


    /**
     * Helper function that returns SQL for getting a sort sequence for a location
     *
     * @param string $locationColumn Column in the full where clause containing
     * the column id
     *
     * @return string
     */
    protected function getItemSortSequenceSQL($locationColumn)
    {
        /*
        if (!$this->useHoldingsSortGroups) {
            return '0 as SORT_SEQ';
        }
        */
        return '(SELECT SORT_GROUP_LOCATION.SEQUENCE_NUMBER ' .
            "FROM $this->dbName.SORT_GROUP, $this->dbName.SORT_GROUP_LOCATION " .
            "WHERE SORT_GROUP.SORT_GROUP_DEFAULT = 'Y' " .
            'AND SORT_GROUP_LOCATION.SORT_GROUP_ID = SORT_GROUP.SORT_GROUP_ID ' .
            "AND SORT_GROUP_LOCATION.LOCATION_ID = $locationColumn) SORT_SEQ";
    }

    /**
     * Protected support method to take an array of status strings and determine
     * whether or not this indicates an available item.  Returns an array with
     * two keys: 'available', the boolean availability status, and 'otherStatuses',
     * every status code found other than "Not Charged" - for use with
     * pickStatus().
     *
     * @param array $statusArray The status codes to analyze.
     *
     * @return array             Availability and other status information.
     */
    protected function determineAvailability($statusArray)
    {
        // It's possible for a record to have multiple status codes.  We
        // need to loop through in search of the "Not Charged" (i.e. on
        // shelf) status, collecting any other statuses we find along the
        // way...
        $notCharged = false;
        $otherStatuses = [];
        foreach ($statusArray as $status) {
            switch ($status) {
                case 'Not Charged':
                    $notCharged = true;
                    break;
                default:
                    $otherStatuses[] = $status;
                    break;
            }
        }
        // If we found other statuses or if we failed to find "Not Charged,"
        // the item is not available!
        $available = (count($otherStatuses) == 0 && $notCharged);
        return ['available' => $available, 'otherStatuses' => $otherStatuses];
    }

    /**
     * Protected support method for getStatus -- get components required for standard
     * status lookup SQL.
     *
     * @param array $id A Bibliographic id
     *
     * @return array Keyed data for use in an sql query
     */
    protected function getStatusSQL($id)
    {
        // Expressions
        $sqlExpressions = [
            "BIB_ITEM.BIB_ID", "ITEM.ITEM_ID",  "MFHD_MASTER.MFHD_ID",
            "ITEM.ON_RESERVE", "ITEM_STATUS_DESC as status",
            "NVL(LOCATION.LOCATION_DISPLAY_NAME, " .
            "LOCATION.LOCATION_NAME) as location",
            "MFHD_MASTER.DISPLAY_CALL_NO as callnumber",
            "ITEM.TEMP_LOCATION", "ITEM.ITEM_TYPE_ID",
            "ITEM.ITEM_SEQUENCE_NUMBER",
            $this->getItemSortSequenceSQL('ITEM.PERM_LOCATION')
        ];
        // From
        $sqlFrom = [
            $this->dbName . ".BIB_ITEM", $this->dbName . ".ITEM",
            $this->dbName . ".ITEM_STATUS_TYPE",
            $this->dbName . ".ITEM_STATUS",
            $this->dbName . ".LOCATION", $this->dbName . ".MFHD_ITEM",
            $this->dbName . ".MFHD_MASTER"
        ];
        // Where
        $sqlWhere = [
            "BIB_ITEM.BIB_ID = :id",
            "BIB_ITEM.ITEM_ID = ITEM.ITEM_ID",
            "ITEM.ITEM_ID = ITEM_STATUS.ITEM_ID",
            "ITEM_STATUS.ITEM_STATUS = ITEM_STATUS_TYPE.ITEM_STATUS_TYPE",
            "LOCATION.LOCATION_ID = ITEM.PERM_LOCATION",
            "MFHD_ITEM.ITEM_ID = ITEM.ITEM_ID",
            "MFHD_MASTER.MFHD_ID = MFHD_ITEM.MFHD_ID",
            "MFHD_MASTER.SUPPRESS_IN_OPAC='N'"
        ];
        // Bind
        $sqlBind = ['id' => $id];
        $sqlArray = [
            'expressions' => $sqlExpressions,
            'from' => $sqlFrom,
            'where' => $sqlWhere,
            'bind' => $sqlBind,
        ];
        return $sqlArray;
    }

    /**
     * Protected support method for getStatus -- get components for status lookup
     * SQL to use when a bib record has no items.
     *
     * @param array $id A Bibliographic id
     *
     * @return array Keyed data for use in an sql query
     */
    protected function getStatusNoItemsSQL($id)
    {
        // Expressions
        $sqlExpressions = [
            "BIB_MFHD.BIB_ID",
            "null as ITEM_ID", "MFHD_MASTER.MFHD_ID", "'N' as ON_RESERVE",
            "'No information available' as status",
            "NVL(LOCATION.LOCATION_DISPLAY_NAME, " .
            "LOCATION.LOCATION_NAME) as location",
            "MFHD_MASTER.DISPLAY_CALL_NO as callnumber",
            "0 AS TEMP_LOCATION",
            "0 as ITEM_SEQUENCE_NUMBER",
            $this->getItemSortSequenceSQL('LOCATION.LOCATION_ID'),
        ];
        // From
        $sqlFrom = [
            $this->dbName . ".BIB_MFHD", $this->dbName . ".LOCATION",
            $this->dbName . ".MFHD_MASTER"
        ];
        // Where
        $sqlWhere = [
            "BIB_MFHD.BIB_ID = :id",
            "LOCATION.LOCATION_ID = MFHD_MASTER.LOCATION_ID",
            "MFHD_MASTER.MFHD_ID = BIB_MFHD.MFHD_ID",
            "MFHD_MASTER.SUPPRESS_IN_OPAC='N'",
            "NOT EXISTS (SELECT MFHD_ID FROM {$this->dbName}.MFHD_ITEM " .
            "WHERE MFHD_ITEM.MFHD_ID=MFHD_MASTER.MFHD_ID)"
        ];
        // Bind
        $sqlBind = ['id' => $id];
        $sqlArray = [
            'expressions' => $sqlExpressions,
            'from' => $sqlFrom,
            'where' => $sqlWhere,
            'bind' => $sqlBind,
        ];
        return $sqlArray;
    }

    /**
     * Protected support method for getStatus -- process rows returned by SQL
     * lookup.
     *
     * @param array $sqlRows Sql Data
     *
     * @return array Keyed data
     */
    protected function getStatusData($sqlRows)
    {
        $data = [];
        foreach ($sqlRows as $row) {
            $rowId = null !== $row['ITEM_ID']
                ? $row['ITEM_ID'] : 'MFHD' . $row['MFHD_ID'];
            if (!isset($data[$rowId])) {
                $data[$rowId] = [
                    'id' => $row['BIB_ID'],
                    'status' => $row['STATUS'],
                    'status_array' => [$row['STATUS']],
                    'location' => $row['TEMP_LOCATION'] > 0
                        ? $this->getLocationName($row['TEMP_LOCATION'])
                        : utf8_encode($row['LOCATION']),
                    'reserve' => $row['ON_RESERVE'],
                    'callnumber' => $row['CALLNUMBER'],
                    'item_sort_seq' => $row['ITEM_SEQUENCE_NUMBER'],
                    'sort_seq' => isset($row['SORT_SEQ'])
                        ? $row['SORT_SEQ']
                        : PHP_INT_MAX
                ];
            } else {
                $statusFound = in_array(
                    $row['STATUS'], $data[$rowId]['status_array']
                );
                if (!$statusFound) {
                    $data[$rowId]['status_array'][] = $row['STATUS'];
                }
            }
        }
        return $data;
    }

    /**
     * Look up a location name by ID.
     *
     * @param int $id Location ID to look up
     *
     * @return string
     */
    protected function getLocationName($id)
    {
        static $cache = [];
        // Fill cache if empty:
        if (!isset($cache[$id])) {
            $sql = "SELECT NVL(LOCATION_DISPLAY_NAME, LOCATION_NAME) as location " .
                "FROM {$this->dbName}.LOCATION WHERE LOCATION_ID=:id";
            $bind = ['id' => $id];
            $sqlStmt = $this->executeSQL($sql, $bind);
            $sqlRow = $sqlStmt->fetch(PDO::FETCH_ASSOC);
            $cache[$id] = utf8_encode($sqlRow['LOCATION']);
        }
        return $cache[$id];
    }

    /**
     * Protected support method for getStatus -- process all details collected by
     * getStatusData().
     *
     * @param array $data SQL Row Data
     *
     * @return array Keyed data
     */
    protected function processStatusData($data)
    {
        // Process the raw data into final status information:
        $status = [];
        foreach ($data as $current) {
            // Get availability/status info based on the array of status codes:
            $availability = $this->determineAvailability($current['status_array']);
            // If we found other statuses, we should override the display value
            // appropriately:
            if (count($availability['otherStatuses']) > 0) {
                $current['status']
                    = $this->pickStatus($availability['otherStatuses']);
            }
            $current['availability'] = $availability['available'];
            $current['use_unknown_message']
                = in_array('No information available', $current['status_array']);
            $status[] = $current;
        }
        /*
        if ($this->useHoldingsSortGroups) {
            usort(
                $status,
                function ($a, $b) {
                    return $a['sort_seq'] == $b['sort_seq']
                        ? $a['item_sort_seq'] - $b['item_sort_seq']
                        : $a['sort_seq'] - $b['sort_seq'];
                }
            );
        }
        */
        return $status;
    }

    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber.
     */
    public function getStatus($id)
    {
        // There are two possible queries we can use to obtain status information.
        // The first (and most common) obtains information from a combination of
        // items and holdings records.  The second (a rare case) obtains
        // information from the holdings record when no items are available.
        $sqlArrayItems = $this->getStatusSQL($id);
        $sqlArrayNoItems = $this->getStatusNoItemsSQL($id);
        $possibleQueries = [
            $this->buildSqlFromArray($sqlArrayItems),
            $this->buildSqlFromArray($sqlArrayNoItems)
        ];

        // Loop through the possible queries and merge results.
        $data = [];
        foreach ($possibleQueries as $sql) {
            // Execute SQL
            try {
                $rows = $this->executeSQL($sql);
            } catch (PDOException $e) {
                throw new Exception($e->getMessage());
            }
            $sqlRows = [];
            foreach ($rows as $row) {
                $sqlRows[] = $row;
            }
            $data += $this->getStatusData($sqlRows);
        }
        return $this->processStatusData($data);
    }

    /**
     * This message uses the base VUFind getStatus method and massages the data
     * to a format required by summon RTA api
     *
     * @param $id the bibliographic id of the record to retrieve status for
     * @return array and array of statuses for the record
     */
    public function getSummonStatus($id)
    {
        $data = [];
        $statuses = $this->getStatus($id);

        foreach($statuses as $status){
            $newStatus = [];
            $newStatus['id'] = $status['id'];
            $newStatus['availability'] = $status['availability'];
            $newStatus['availability_message'] = (isset($this->statusMap[$status['status']])) ? $this->statusMap[$status['status']] : $status['status'];
            $newStatus['location'] = $status['location'];
            $newStatus['locationList'] = false;
            $newStatus['callnumber'] = $status['callnumber'];
            $newStatus['locationList'] = false;
            $newStatus['reserve'] = ($status['reserve'] === 'Y') ? true : false;
            $newStatus['reserve_message'] = $newStatus['reserve'] ? $this->reserveMessage : '';
            $data[] = $newStatus;
        }

        return $data;
    }

    /**
     * Retrieves a list of item status for the records passed via the array of ids
     *
     * @param array $ids of the records to retrieve status
     *
     * @return array summon RTA message format
     */
    public function getSummonStatuses($ids = [])
    {
        $data = [];
        foreach($ids as $id){
            $status = $this->getSummonStatus($id);
            $data = array_merge($data,$status);

        }
        $statuses = [];
        $statuses['data'] = $data;

        return $statuses;

    }

    /**
     * Execute an SQL query
     *
     * @param string|array $sql  SQL statement (string or array that includes
     * bind params)
     * @param array        $bind Bind parameters (if $sql is string)
     *
     * @return PDOStatement
     */
    protected function executeSQL($sql, $bind = [])
    {
        if (is_array($sql)) {
            $bind = $sql['bind'];
            $sql = $sql['string'];
        }
        /*
        if ($this->logger) {
            list(, $caller) = debug_backtrace(false);
            $this->debugSQL($caller['function'], $sql, $bind);
        }
        */

        $sqlStmt = $this->db->prepare($sql);
        foreach($bind as $name => $value){
            $sqlStmt->bindValue($name, $value);
        }
        $sqlStmt->execute();

        return $sqlStmt->fetchAll();
    }
}