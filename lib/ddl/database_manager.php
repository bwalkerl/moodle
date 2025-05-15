<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Database manager instance is responsible for all database structure modifications.
 *
 * @package    core_ddl
 * @copyright  1999 onwards Martin Dougiamas     http://dougiamas.com
 *             2001-3001 Eloy Lafuente (stronk7) http://contiento.com
 *             2008 Petr Skoda                   http://skodak.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use core\check\result;

/**
 * Database manager instance is responsible for all database structure modifications.
 *
 * It is using db specific generators to find out the correct SQL syntax to do that.
 *
 * @package    core_ddl
 * @copyright  1999 onwards Martin Dougiamas     http://dougiamas.com
 *             2001-3001 Eloy Lafuente (stronk7) http://contiento.com
 *             2008 Petr Skoda                   http://skodak.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class database_manager {

    /** @var moodle_database A moodle_database driver specific instance.*/
    protected $mdb;

    /** @var sql_generator A driver specific SQL generator instance. Public because XMLDB editor needs to access it.*/
    public $generator;

    /**
     * Creates a new database manager instance.
     * @param moodle_database $mdb A moodle_database driver specific instance.
     * @param sql_generator $generator A driver specific SQL generator instance.
     */
    public function __construct($mdb, $generator) {
        $this->mdb       = $mdb;
        $this->generator = $generator;
    }

    /**
     * Releases all resources
     */
    public function dispose() {
        if ($this->generator) {
            $this->generator->dispose();
            $this->generator = null;
        }
        $this->mdb = null;
    }

    /**
     * This function will execute an array of SQL commands.
     *
     * @param string[] $sqlarr Array of sql statements to execute.
     * @param array|null $tablenames an array of xmldb table names affected by this request.
     * @throws ddl_change_structure_exception This exception is thrown if any error is found.
     */
    protected function execute_sql_arr(array $sqlarr, $tablenames = null) {
        $this->mdb->change_database_structure($sqlarr, $tablenames);
    }

    /**
     * Execute a given sql command string.
     *
     * @param string $sql The sql string you wish to be executed.
     * @throws ddl_change_structure_exception This exception is thrown if any error is found.
     */
    protected function execute_sql($sql) {
        $this->mdb->change_database_structure($sql);
    }

    /**
     * Given one xmldb_table, check if it exists in DB (true/false).
     *
     * @param string|xmldb_table $table The table to be searched (string name or xmldb_table instance).
     * @return bool True is a table exists, false otherwise.
     */
    public function table_exists($table) {
        if (!is_string($table) and !($table instanceof xmldb_table)) {
            throw new ddl_exception('ddlunknownerror', NULL, 'incorrect table parameter!');
        }
        return $this->generator->table_exists($table);
    }

    /**
     * Reset a sequence to the id field of a table.
     * @param string|xmldb_table $table Name of table.
     * @throws ddl_exception thrown upon reset errors.
     */
    public function reset_sequence($table) {
        if (!is_string($table) and !($table instanceof xmldb_table)) {
            throw new ddl_exception('ddlunknownerror', NULL, 'incorrect table parameter!');
        } else {
            if ($table instanceof xmldb_table) {
                $tablename = $table->getName();
            } else {
                $tablename = $table;
            }
        }

        // Do not test if table exists because it is slow

        if (!$sqlarr = $this->generator->getResetSequenceSQL($table)) {
            throw new ddl_exception('ddlunknownerror', null, 'table reset sequence sql not generated');
        }

        $this->execute_sql_arr($sqlarr, array($tablename));
    }

    /**
     * Given one xmldb_field, check if it exists in DB (true/false).
     *
     * @param string|xmldb_table $table The table to be searched (string name or xmldb_table instance).
     * @param string|xmldb_field $field The field to be searched for (string name or xmldb_field instance).
     * @return boolean true is exists false otherwise.
     * @throws ddl_table_missing_exception
     */
    public function field_exists($table, $field) {
        // Calculate the name of the table
        if (is_string($table)) {
            $tablename = $table;
        } else {
            $tablename = $table->getName();
        }

        // Check the table exists
        if (!$this->table_exists($table)) {
            throw new ddl_table_missing_exception($tablename);
        }

        if (is_string($field)) {
            $fieldname = $field;
        } else {
            // Calculate the name of the table
            $fieldname = $field->getName();
        }

        // Get list of fields in table
        $columns = $this->mdb->get_columns($tablename);

        $exists = array_key_exists($fieldname,  $columns);

        return $exists;
    }

    /**
     * Given one xmldb_index, the function returns the name of the index in DB
     * of false if it doesn't exist
     *
     * @param xmldb_table $xmldb_table table to be searched
     * @param xmldb_index $xmldb_index the index to be searched
     * @param bool $returnall true means return array of all indexes, false means first index only as string
     * @return array|string|bool Index name, array of index names or false if no indexes are found.
     * @throws ddl_table_missing_exception Thrown when table is not found.
     */
    public function find_index_name(xmldb_table $xmldb_table, xmldb_index $xmldb_index, $returnall = false) {
        // Calculate the name of the table
        $tablename = $xmldb_table->getName();

        // Check the table exists
        if (!$this->table_exists($xmldb_table)) {
            throw new ddl_table_missing_exception($tablename);
        }

        // Extract index columns
        $indcolumns = $xmldb_index->getFields();

        // Get list of indexes in table
        $indexes = $this->mdb->get_indexes($tablename);

        $return = array();

        // Iterate over them looking for columns coincidence
        foreach ($indexes as $indexname => $index) {
            $columns = $index['columns'];
            // Check if index matches queried index
            $diferences = array_merge(array_diff($columns, $indcolumns), array_diff($indcolumns, $columns));
            // If no differences, we have find the index
            if (empty($diferences)) {
                if ($returnall) {
                    $return[] = $indexname;
                } else {
                    return $indexname;
                }
            }
        }

        if ($return and $returnall) {
            return $return;
        }

        // Arriving here, index not found
        return false;
    }

    /**
     * Given one xmldb_index, check if it exists in DB (true/false).
     *
     * @param xmldb_table $xmldb_table The table to be searched.
     * @param xmldb_index $xmldb_index The index to be searched for.
     * @return boolean true id index exists, false otherwise.
     */
    public function index_exists(xmldb_table $xmldb_table, xmldb_index $xmldb_index) {
        if (!$this->table_exists($xmldb_table)) {
            return false;
        }
        return ($this->find_index_name($xmldb_table, $xmldb_index) !== false);
    }

    /**
     * This function IS NOT IMPLEMENTED. ONCE WE'LL BE USING RELATIONAL
     * INTEGRITY IT WILL BECOME MORE USEFUL. FOR NOW, JUST CALCULATE "OFFICIAL"
     * KEY NAMES WITHOUT ACCESSING TO DB AT ALL.
     * Given one xmldb_key, the function returns the name of the key in DB (if exists)
     * of false if it doesn't exist
     *
     * @param xmldb_table $xmldb_table The table to be searched.
     * @param xmldb_key $xmldb_key The key to be searched.
     * @return string key name if found
     */
    public function find_key_name(xmldb_table $xmldb_table, xmldb_key $xmldb_key) {

        $keycolumns = $xmldb_key->getFields();

        // Get list of keys in table
        // first primaries (we aren't going to use this now, because the MetaPrimaryKeys is awful)
            //TODO: To implement when we advance in relational integrity
        // then uniques (note that Moodle, for now, shouldn't have any UNIQUE KEY for now, but unique indexes)
            //TODO: To implement when we advance in relational integrity (note that AdoDB hasn't any MetaXXX for this.
        // then foreign (note that Moodle, for now, shouldn't have any FOREIGN KEY for now, but indexes)
            //TODO: To implement when we advance in relational integrity (note that AdoDB has one MetaForeignKeys()
            //but it's far from perfect.
        // TODO: To create the proper functions inside each generator to retrieve all the needed KEY info (name
        //       columns, reftable and refcolumns

        // So all we do is to return the official name of the requested key without any confirmation!)
        // One exception, hardcoded primary constraint names
        if ($this->generator->primary_key_name && $xmldb_key->getType() == XMLDB_KEY_PRIMARY) {
            return $this->generator->primary_key_name;
        } else {
            // Calculate the name suffix
            switch ($xmldb_key->getType()) {
                case XMLDB_KEY_PRIMARY:
                    $suffix = 'pk';
                    break;
                case XMLDB_KEY_UNIQUE:
                    $suffix = 'uk';
                    break;
                case XMLDB_KEY_FOREIGN_UNIQUE:
                case XMLDB_KEY_FOREIGN:
                    $suffix = 'fk';
                    break;
            }
            // And simply, return the official name
            return $this->generator->getNameForObject($xmldb_table->getName(), implode(', ', $xmldb_key->getFields()), $suffix);
        }
    }

    /**
     * This function will delete all tables found in XMLDB file from db
     *
     * @param string $file Full path to the XML file to be used.
     * @return void
     */
    public function delete_tables_from_xmldb_file($file) {

        $xmldb_file = new xmldb_file($file);

        if (!$xmldb_file->fileExists()) {
            throw new ddl_exception('ddlxmlfileerror', null, 'File does not exist');
        }

        $loaded    = $xmldb_file->loadXMLStructure();
        $structure = $xmldb_file->getStructure();

        if (!$loaded || !$xmldb_file->isLoaded()) {
            // Show info about the error if we can find it
            if ($structure) {
                if ($errors = $structure->getAllErrors()) {
                    throw new ddl_exception('ddlxmlfileerror', null, 'Errors found in XMLDB file: '. implode (', ', $errors));
                }
            }
            throw new ddl_exception('ddlxmlfileerror', null, 'not loaded??');
        }

        if ($xmldb_tables = $structure->getTables()) {
            // Delete in opposite order, this should help with foreign keys in the future.
            $xmldb_tables = array_reverse($xmldb_tables);
            foreach($xmldb_tables as $table) {
                if ($this->table_exists($table)) {
                    $this->drop_table($table);
                }
            }
        }
    }

    /**
     * This function will drop the table passed as argument
     * and all the associated objects (keys, indexes, constraints, sequences, triggers)
     * will be dropped too.
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @return void
     */
    public function drop_table(xmldb_table $xmldb_table) {
        // Check table exists
        if (!$this->table_exists($xmldb_table)) {
            throw new ddl_table_missing_exception($xmldb_table->getName());
        }

        if (!$sqlarr = $this->generator->getDropTableSQL($xmldb_table)) {
            throw new ddl_exception('ddlunknownerror', null, 'table drop sql not generated');
        }
        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));

        $this->generator->cleanup_after_drop($xmldb_table);
    }

    /**
     * Load an install.xml file, checking that it exists, and that the structure is OK.
     * @param string $file the full path to the XMLDB file.
     * @return xmldb_file the loaded file.
     */
    private function load_xmldb_file($file) {
        $xmldb_file = new xmldb_file($file);

        if (!$xmldb_file->fileExists()) {
            throw new ddl_exception('ddlxmlfileerror', null, 'File does not exist');
        }

        $loaded = $xmldb_file->loadXMLStructure();
        if (!$loaded || !$xmldb_file->isLoaded()) {
            // Show info about the error if we can find it
            if ($structure = $xmldb_file->getStructure()) {
                if ($errors = $structure->getAllErrors()) {
                    throw new ddl_exception('ddlxmlfileerror', null, 'Errors found in XMLDB file: '. implode (', ', $errors));
                }
            }
            throw new ddl_exception('ddlxmlfileerror', null, 'not loaded??');
        }

        return $xmldb_file;
    }

    /**
     * This function will load one entire XMLDB file and call install_from_xmldb_structure.
     *
     * @param string $file full path to the XML file to be used
     * @return void
     */
    public function install_from_xmldb_file($file) {
        $xmldb_file = $this->load_xmldb_file($file);
        $xmldb_structure = $xmldb_file->getStructure();
        $this->install_from_xmldb_structure($xmldb_structure);
    }

    /**
     * This function will load one entire XMLDB file and call install_from_xmldb_structure.
     *
     * @param string $file full path to the XML file to be used
     * @param string $tablename the name of the table.
     * @param bool $cachestructures boolean to decide if loaded xmldb structures can be safely cached
     *             useful for testunits loading the enormous main xml file hundred of times (100x)
     */
    public function install_one_table_from_xmldb_file($file, $tablename, $cachestructures = false) {

        static $xmldbstructurecache = array(); // To store cached structures
        if (!empty($xmldbstructurecache) && array_key_exists($file, $xmldbstructurecache)) {
            $xmldb_structure = $xmldbstructurecache[$file];
        } else {
            $xmldb_file = $this->load_xmldb_file($file);
            $xmldb_structure = $xmldb_file->getStructure();
            if ($cachestructures) {
                $xmldbstructurecache[$file] = $xmldb_structure;
            }
        }

        $targettable = $xmldb_structure->getTable($tablename);
        if (is_null($targettable)) {
            throw new ddl_exception('ddlunknowntable', null, 'The table ' . $tablename . ' is not defined in file ' . $file);
        }
        $targettable->setNext(NULL);
        $targettable->setPrevious(NULL);

        $tempstructure = new xmldb_structure('temp');
        $tempstructure->addTable($targettable);
        $this->install_from_xmldb_structure($tempstructure);
    }

    /**
     * This function will generate all the needed SQL statements, specific for each
     * RDBMS type and, finally, it will execute all those statements against the DB.
     *
     * @param stdClass $xmldb_structure xmldb_structure object.
     * @return void
     */
    public function install_from_xmldb_structure($xmldb_structure) {

        if (!$sqlarr = $this->generator->getCreateStructureSQL($xmldb_structure)) {
            return; // nothing to do
        }

        $tablenames = array();
        foreach ($xmldb_structure as $xmldb_table) {
            if ($xmldb_table instanceof xmldb_table) {
                $tablenames[] = $xmldb_table->getName();
            }
        }
        $this->execute_sql_arr($sqlarr, $tablenames);
    }

    /**
     * This function will create the table passed as argument with all its
     * fields/keys/indexes/sequences, everything based in the XMLDB object
     *
     * @param xmldb_table $xmldb_table Table object (full specs are required).
     * @return void
     */
    public function create_table(xmldb_table $xmldb_table) {
        // Check table doesn't exist
        if ($this->table_exists($xmldb_table)) {
            throw new ddl_exception('ddltablealreadyexists', $xmldb_table->getName());
        }

        if (!$sqlarr = $this->generator->getCreateTableSQL($xmldb_table)) {
            throw new ddl_exception('ddlunknownerror', null, 'table create sql not generated');
        }
        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
    }

    /**
     * This function will create the temporary table passed as argument with all its
     * fields/keys/indexes/sequences, everything based in the XMLDB object
     *
     * If table already exists ddl_exception will be thrown, please make sure
     * the table name does not collide with existing normal table!
     *
     * @param xmldb_table $xmldb_table Table object (full specs are required).
     * @return void
     */
    public function create_temp_table(xmldb_table $xmldb_table) {

        // Check table doesn't exist
        if ($this->table_exists($xmldb_table)) {
            throw new ddl_exception('ddltablealreadyexists', $xmldb_table->getName());
        }

        if (!$sqlarr = $this->generator->getCreateTempTableSQL($xmldb_table)) {
            throw new ddl_exception('ddlunknownerror', null, 'temp table create sql not generated');
        }
        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
    }

    /**
     * This function will drop the temporary table passed as argument with all its
     * fields/keys/indexes/sequences, everything based in the XMLDB object
     *
     * It is recommended to drop temp table when not used anymore.
     *
     * @deprecated since 2.3, use drop_table() for all table types
     * @param xmldb_table $xmldb_table Table object.
     * @return void
     */
    public function drop_temp_table(xmldb_table $xmldb_table) {
        debugging('database_manager::drop_temp_table() is deprecated, use database_manager::drop_table() instead');
        $this->drop_table($xmldb_table);
    }

    /**
     * This function will rename the table passed as argument
     * Before renaming the index, the function will check it exists
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param string $newname New name of the index.
     * @return void
     */
    public function rename_table(xmldb_table $xmldb_table, $newname) {
        // Check newname isn't empty
        if (!$newname) {
            throw new ddl_exception('ddlunknownerror', null, 'newname can not be empty');
        }

        $check = new xmldb_table($newname);

        // Check table already renamed
        if (!$this->table_exists($xmldb_table)) {
            if ($this->table_exists($check)) {
                throw new ddl_exception('ddlunknownerror', null, 'table probably already renamed');
            } else {
                throw new ddl_table_missing_exception($xmldb_table->getName());
            }
        }

        // Check new table doesn't exist
        if ($this->table_exists($check)) {
            throw new ddl_exception('ddltablealreadyexists', $check->getName(), 'can not rename table');
        }

        if (!$sqlarr = $this->generator->getRenameTableSQL($xmldb_table, $newname)) {
            throw new ddl_exception('ddlunknownerror', null, 'table rename sql not generated');
        }

        $this->execute_sql_arr($sqlarr);
    }

    /**
     * This function will add the field to the table passed as arguments
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_field $xmldb_field Index object (full specs are required).
     * @return void
     */
    public function add_field(xmldb_table $xmldb_table, xmldb_field $xmldb_field) {
         // Check the field doesn't exist
        if ($this->field_exists($xmldb_table, $xmldb_field)) {
            throw new ddl_exception('ddlfieldalreadyexists', $xmldb_field->getName());
        }

        // If NOT NULL and no default given (we ask the generator about the
        // *real* default that will be used) check the table is empty
        if ($xmldb_field->getNotNull() && $this->generator->getDefaultValue($xmldb_field) === NULL && $this->mdb->count_records($xmldb_table->getName())) {
            throw new ddl_exception('ddlunknownerror', null, 'Field ' . $xmldb_table->getName() . '->' . $xmldb_field->getName() .
                      ' cannot be added. Not null fields added to non empty tables require default value. Create skipped');
        }

        if (!$sqlarr = $this->generator->getAddFieldSQL($xmldb_table, $xmldb_field)) {
            throw new ddl_exception('ddlunknownerror', null, 'addfield sql not generated');
        }
        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
    }

    /**
     * This function will drop the field from the table passed as arguments
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_field $xmldb_field Index object (full specs are required).
     * @return void
     */
    public function drop_field(xmldb_table $xmldb_table, xmldb_field $xmldb_field) {
        if (!$this->table_exists($xmldb_table)) {
            throw new ddl_table_missing_exception($xmldb_table->getName());
        }
        // Check the field exists
        if (!$this->field_exists($xmldb_table, $xmldb_field)) {
            throw new ddl_field_missing_exception($xmldb_field->getName(), $xmldb_table->getName());
        }
        // Check for dependencies in the DB before performing any action
        $this->check_field_dependencies($xmldb_table, $xmldb_field);

        if (!$sqlarr = $this->generator->getDropFieldSQL($xmldb_table, $xmldb_field)) {
            throw new ddl_exception('ddlunknownerror', null, 'drop_field sql not generated');
        }

        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
    }

    /**
     * This function will change the type of the field in the table passed as arguments
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_field $xmldb_field Index object (full specs are required).
     * @return void
     */
    public function change_field_type(xmldb_table $xmldb_table, xmldb_field $xmldb_field) {
        if (!$this->table_exists($xmldb_table)) {
            throw new ddl_table_missing_exception($xmldb_table->getName());
        }
        // Check the field exists
        if (!$this->field_exists($xmldb_table, $xmldb_field)) {
            throw new ddl_field_missing_exception($xmldb_field->getName(), $xmldb_table->getName());
        }
        // Check for dependencies in the DB before performing any action
        $this->check_field_dependencies($xmldb_table, $xmldb_field);

        if (!$sqlarr = $this->generator->getAlterFieldSQL($xmldb_table, $xmldb_field)) {
            return; // probably nothing to do
        }

        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
    }

    /**
     * This function will change the precision of the field in the table passed as arguments
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_field $xmldb_field Index object (full specs are required).
     * @return void
     */
    public function change_field_precision(xmldb_table $xmldb_table, xmldb_field $xmldb_field) {
        // Just a wrapper over change_field_type. Does exactly the same processing
        $this->change_field_type($xmldb_table, $xmldb_field);
    }

    /**
     * This function will change the unsigned/signed of the field in the table passed as arguments
     *
     * @deprecated since 2.3, only singed numbers are allowed now, migration is automatic
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_field $xmldb_field Field object (full specs are required).
     * @return void
     */
    public function change_field_unsigned(xmldb_table $xmldb_table, xmldb_field $xmldb_field) {
        debugging('All unsigned numbers are converted to signed automatically during Moodle upgrade.');
        $this->change_field_type($xmldb_table, $xmldb_field);
    }

    /**
     * This function will change the nullability of the field in the table passed as arguments
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_field $xmldb_field Index object (full specs are required).
     * @return void
     */
    public function change_field_notnull(xmldb_table $xmldb_table, xmldb_field $xmldb_field) {
        // Just a wrapper over change_field_type. Does exactly the same processing
        $this->change_field_type($xmldb_table, $xmldb_field);
    }

    /**
     * This function will change the default of the field in the table passed as arguments
     * One null value in the default field means delete the default
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_field $xmldb_field Index object (full specs are required).
     * @return void
     */
    public function change_field_default(xmldb_table $xmldb_table, xmldb_field $xmldb_field) {
        if (!$this->table_exists($xmldb_table)) {
            throw new ddl_table_missing_exception($xmldb_table->getName());
        }
        // Check the field exists
        if (!$this->field_exists($xmldb_table, $xmldb_field)) {
            throw new ddl_field_missing_exception($xmldb_field->getName(), $xmldb_table->getName());
        }
        // Check for dependencies in the DB before performing any action
        $this->check_field_dependencies($xmldb_table, $xmldb_field);

        if (!$sqlarr = $this->generator->getModifyDefaultSQL($xmldb_table, $xmldb_field)) {
            return; //Empty array = nothing to do = no error
        }

        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
    }

    /**
     * This function will rename the field in the table passed as arguments
     * Before renaming the field, the function will check it exists
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_field $xmldb_field Index object (full specs are required).
     * @param string $newname New name of the field.
     * @return void
     */
    public function rename_field(xmldb_table $xmldb_table, xmldb_field $xmldb_field, $newname) {
        if (empty($newname)) {
            throw new ddl_exception('ddlunknownerror', null, 'newname can not be empty');
        }

        if (!$this->table_exists($xmldb_table)) {
            throw new ddl_table_missing_exception($xmldb_table->getName());
        }

        // Check the field exists
        if (!$this->field_exists($xmldb_table, $xmldb_field)) {
            throw new ddl_field_missing_exception($xmldb_field->getName(), $xmldb_table->getName());
        }

        // Check we have included full field specs
        if (!$xmldb_field->getType()) {
            throw new ddl_exception('ddlunknownerror', null,
                      'Field ' . $xmldb_table->getName() . '->' . $xmldb_field->getName() .
                      ' must contain full specs. Rename skipped');
        }

        // Check field isn't id. Renaming over that field is not allowed
        if ($xmldb_field->getName() == 'id') {
            throw new ddl_exception('ddlunknownerror', null,
                      'Field ' . $xmldb_table->getName() . '->' . $xmldb_field->getName() .
                      ' cannot be renamed. Rename skipped');
        }

        if (!$sqlarr = $this->generator->getRenameFieldSQL($xmldb_table, $xmldb_field, $newname)) {
            return; //Empty array = nothing to do = no error
        }

        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
    }

    /**
     * This function will check, for the given table and field, if there there is any dependency
     * preventing the field to be modified. It's used by all the public methods that perform any
     * DDL change on fields, throwing one ddl_dependency_exception if dependencies are found.
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_field $xmldb_field Index object (full specs are required).
     * @return void
     * @throws ddl_dependency_exception|ddl_field_missing_exception|ddl_table_missing_exception if dependency not met.
     */
    private function check_field_dependencies(xmldb_table $xmldb_table, xmldb_field $xmldb_field) {

        // Check the table exists
        if (!$this->table_exists($xmldb_table)) {
            throw new ddl_table_missing_exception($xmldb_table->getName());
        }

        // Check the field exists
        if (!$this->field_exists($xmldb_table, $xmldb_field)) {
            throw new ddl_field_missing_exception($xmldb_field->getName(), $xmldb_table->getName());
        }

        // Check the field isn't in use by any index in the table
        if ($indexes = $this->mdb->get_indexes($xmldb_table->getName(), false)) {
            foreach ($indexes as $indexname => $index) {
                $columns = $index['columns'];
                if (in_array($xmldb_field->getName(), $columns)) {
                    throw new ddl_dependency_exception('column', $xmldb_table->getName() . '->' . $xmldb_field->getName(),
                                                       'index', $indexname . ' (' . implode(', ', $columns)  . ')');
                }
            }
        }
    }

    /**
     * This function will create the key in the table passed as arguments
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_key $xmldb_key Index object (full specs are required).
     * @return void
     */
    public function add_key(xmldb_table $xmldb_table, xmldb_key $xmldb_key) {

        if ($xmldb_key->getType() == XMLDB_KEY_PRIMARY) { // Prevent PRIMARY to be added (only in create table, being serious  :-P)
            throw new ddl_exception('ddlunknownerror', null, 'Primary Keys can be added at table create time only');
        }

        if (!$sqlarr = $this->generator->getAddKeySQL($xmldb_table, $xmldb_key)) {
            return; //Empty array = nothing to do = no error
        }

        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
    }

    /**
     * This function will drop the key in the table passed as arguments
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_key $xmldb_key Key object (full specs are required).
     * @return void
     */
    public function drop_key(xmldb_table $xmldb_table, xmldb_key $xmldb_key) {
        if ($xmldb_key->getType() == XMLDB_KEY_PRIMARY) { // Prevent PRIMARY to be dropped (only in drop table, being serious  :-P)
            throw new ddl_exception('ddlunknownerror', null, 'Primary Keys can be deleted at table drop time only');
        }

        if (!$sqlarr = $this->generator->getDropKeySQL($xmldb_table, $xmldb_key)) {
            return; //Empty array = nothing to do = no error
        }

        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
    }

    /**
     * This function will rename the key in the table passed as arguments
     * Experimental. Shouldn't be used at all in normal installation/upgrade!
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_key $xmldb_key key object (full specs are required).
     * @param string $newname New name of the key.
     * @return void
     */
    public function rename_key(xmldb_table $xmldb_table, xmldb_key $xmldb_key, $newname) {
        debugging('rename_key() is one experimental feature. You must not use it in production!', DEBUG_DEVELOPER);

        // Check newname isn't empty
        if (!$newname) {
            throw new ddl_exception('ddlunknownerror', null, 'newname can not be empty');
        }

        if (!$sqlarr = $this->generator->getRenameKeySQL($xmldb_table, $xmldb_key, $newname)) {
            throw new ddl_exception('ddlunknownerror', null, 'Some DBs do not support key renaming (MySQL, PostgreSQL, MsSQL). Rename skipped');
        }

        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
    }

    /**
     * This function will create the index in the table passed as arguments
     * Before creating the index, the function will check it doesn't exists
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_index $xmldb_intex Index object (full specs are required).
     * @return void
     */
    public function add_index($xmldb_table, $xmldb_intex) {
        if (!$this->table_exists($xmldb_table)) {
            throw new ddl_table_missing_exception($xmldb_table->getName());
        }

        // Check index doesn't exist
        if ($this->index_exists($xmldb_table, $xmldb_intex)) {
            throw new ddl_exception('ddlunknownerror', null,
                      'Index ' . $xmldb_table->getName() . '->' . $xmldb_intex->getName() .
                      ' already exists. Create skipped');
        }

        if (!$sqlarr = $this->generator->getAddIndexSQL($xmldb_table, $xmldb_intex)) {
            throw new ddl_exception('ddlunknownerror', null, 'add_index sql not generated');
        }

        try {
            $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
        } catch (ddl_change_structure_exception $e) {
            // There could be a problem with the index length related to the row format of the table.
            // If we are using utf8mb4 and the row format is 'compact' or 'redundant' then we need to change it over to
            // 'compressed' or 'dynamic'.
            if (method_exists($this->mdb, 'convert_table_row_format')) {
                $this->mdb->convert_table_row_format($xmldb_table->getName());
                $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
            } else {
                // It's some other problem that we are currently not handling.
                throw $e;
            }
        }
    }

    /**
     * This function will drop the index in the table passed as arguments
     * Before dropping the index, the function will check it exists
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_index $xmldb_intex Index object (full specs are required).
     * @return void
     */
    public function drop_index($xmldb_table, $xmldb_intex) {
        if (!$this->table_exists($xmldb_table)) {
            throw new ddl_table_missing_exception($xmldb_table->getName());
        }

        // Check index exists
        if (!$this->index_exists($xmldb_table, $xmldb_intex)) {
            throw new ddl_exception('ddlunknownerror', null,
                      'Index ' . $xmldb_table->getName() . '->' . $xmldb_intex->getName() .
                      ' does not exist. Drop skipped');
        }

        if (!$sqlarr = $this->generator->getDropIndexSQL($xmldb_table, $xmldb_intex)) {
            throw new ddl_exception('ddlunknownerror', null, 'drop_index sql not generated');
        }

        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
    }

    /**
     * This function will rename the index in the table passed as arguments
     * Before renaming the index, the function will check it exists
     * Experimental. Shouldn't be used at all!
     *
     * @param xmldb_table $xmldb_table Table object (just the name is mandatory).
     * @param xmldb_index $xmldb_intex Index object (full specs are required).
     * @param string $newname New name of the index.
     * @return void
     */
    public function rename_index($xmldb_table, $xmldb_intex, $newname) {
        debugging('rename_index() is one experimental feature. You must not use it in production!', DEBUG_DEVELOPER);

        // Check newname isn't empty
        if (!$newname) {
            throw new ddl_exception('ddlunknownerror', null, 'newname can not be empty');
        }

        // Check index exists
        if (!$this->index_exists($xmldb_table, $xmldb_intex)) {
            throw new ddl_exception('ddlunknownerror', null,
                      'Index ' . $xmldb_table->getName() . '->' . $xmldb_intex->getName() .
                      ' does not exist. Rename skipped');
        }

        if (!$sqlarr = $this->generator->getRenameIndexSQL($xmldb_table, $xmldb_intex, $newname)) {
            throw new ddl_exception('ddlunknownerror', null, 'Some DBs do not support index renaming (MySQL). Rename skipped');
        }

        $this->execute_sql_arr($sqlarr, array($xmldb_table->getName()));
    }

    /**
     * Get the list of install.xml files.
     *
     * @return array
     */
    public function get_install_xml_files(): array {
        global $CFG;
        require_once($CFG->libdir.'/adminlib.php');

        $files = [];
        $dbdirs = get_db_directories();
        foreach ($dbdirs as $dbdir) {
            $filename = "{$dbdir}/install.xml";
            if (file_exists($filename)) {
                $files[] = $filename;
            }
        }

        return $files;
    }

    /**
     * Reads the install.xml files for Moodle core and modules and returns an array of
     * xmldb_structure object with xmldb_table from these files.
     * @return xmldb_structure schema from install.xml files
     */
    public function get_install_xml_schema() {
        global $CFG;
        require_once($CFG->libdir.'/adminlib.php');

        $schema = new xmldb_structure('export');
        $schema->setVersion($CFG->version);

        foreach ($this->get_install_xml_files() as $filename) {
            $xmldb_file = new xmldb_file($filename);
            if (!$xmldb_file->loadXMLStructure()) {
                continue;
            }
            $structure = $xmldb_file->getStructure();
            $tables = $structure->getTables();
            foreach ($tables as $table) {
                $table->setPrevious(null);
                $table->setNext(null);
                $schema->addTable($table);
            }
        }
        return $schema;
    }

    /**
     * Checks the database schema against a schema specified by an xmldb_structure object
     * @param xmldb_structure $schema export schema describing all known tables
     * @param array $options
     * @param bool $fulldetails return table name with full details instead of only a description
     * @return array keyed by table name with array of difference messages as values
     */
    public function check_database_schema(xmldb_structure $schema, ?array $options = null, bool $fulldetails = false) {
        $alloptions = array(
            'extratables' => true,
            'missingtables' => true,
            'extracolumns' => true,
            'missingcolumns' => true,
            'changedcolumns' => true,
            'missingindexes' => true,
            'extraindexes' => true
        );

        $typesmap = array(
            'I' => XMLDB_TYPE_INTEGER,
            'R' => XMLDB_TYPE_INTEGER,
            'N' => XMLDB_TYPE_NUMBER,
            'F' => XMLDB_TYPE_NUMBER, // Nobody should be using floats!
            'C' => XMLDB_TYPE_CHAR,
            'X' => XMLDB_TYPE_TEXT,
            'B' => XMLDB_TYPE_BINARY,
            'T' => XMLDB_TYPE_TIMESTAMP,
            'D' => XMLDB_TYPE_DATETIME,
        );

        $options = (array)$options;
        $options = array_merge($alloptions, $options);

        // Note: the error descriptions are not supposed to be localised,
        //       it is intended for developers and skilled admins only.
        $errors = array();

        /** @var string[] $dbtables */
        $dbtables = $this->mdb->get_tables(false);
        /** @var xmldb_table[] $tables */
        $tables = $schema->getTables();

        foreach ($tables as $table) {
            $tablename = $table->getName();

            if ($options['missingtables']) {
                // Missing tables are a fatal problem.
                if (empty($dbtables[$tablename])) {
                    $errors[$tablename][] = (object) [
                        'desc' => 'table is missing',
                        'type' => 'missingtables',
                        'table' => $table,
                        'status' => result::ERROR,
                        'safety' => 'safe',
                    ];
                    continue;
                }
            }

            /** @var database_column_info[] $dbfields */
            $dbfields = $this->mdb->get_columns($tablename, false);
            $dbindexes = $this->mdb->get_indexes($tablename);
            /** @var xmldb_field[] $fields */
            $fields = $table->getFields();

            foreach ($fields as $field) {
                $fieldname = $field->getName();
                if (empty($dbfields[$fieldname])) {
                    if ($options['missingcolumns']) {
                        // Missing columns are a fatal problem.
                        $errors[$tablename][] = (object) [
                            'desc' => "column '$fieldname' is missing",
                            'type' => 'missingcolumns',
                            'field' => $field,
                            'status' => result::ERROR,
                            'safety' => 'safe',
                        ];
                    }
                } else if ($options['changedcolumns']) {
                    $dbfield = $dbfields[$fieldname];

                    if (!isset($typesmap[$dbfield->meta_type])) {
                        $errors[$tablename][] = (object) [
                            'desc' => "column '$fieldname' has unsupported type '$dbfield->meta_type'",
                            'type' => 'changedcolumns',
                            'issue' => 'type',
                            'field' => $field,
                            'dbfield' => $dbfield,
                            'status' => result::WARNING,
                            'safety' => 'risky',
                        ];
                    } else {
                        $dbtype = $typesmap[$dbfield->meta_type];
                        $type = $field->getType();
                        if ($type == XMLDB_TYPE_FLOAT) {
                            $type = XMLDB_TYPE_NUMBER;
                        }
                        if ($type != $dbtype) {
                            if ($expected = array_search($type, $typesmap)) {
                                $errors[$tablename][] = (object) [
                                    'desc' => "column '$fieldname' has incorrect type '$dbfield->meta_type', expected '$expected'",
                                    'type' => 'changedcolumns',
                                    'issue' => 'type',
                                    'field' => $field,
                                    'dbfield' => $dbfield,
                                    'status' => result::WARNING,
                                    'safety' => 'risky',
                                ];
                            } else {
                                $errors[$tablename][] = (object) [
                                    'desc' => "column '$fieldname' has incorrect type '$dbfield->meta_type'",
                                    'type' => 'changedcolumns',
                                    'issue' => 'type',
                                    'field' => $field,
                                    'dbfield' => $dbfield,
                                    'status' => result::WARNING,
                                    'safety' => 'risky',
                                ];
                            }
                        } else {
                            if ($field->getNotNull() != $dbfield->not_null) {
                                if ($field->getNotNull()) {
                                    $errors[$tablename][] = (object) [
                                        'desc' => "column '$fieldname' should be NOT NULL ($dbfield->meta_type)",
                                        'type' => 'changedcolumns',
                                        'issue' => 'null',
                                        'field' => $field,
                                        'dbfield' => $dbfield,
                                        'status' => result::WARNING,
                                        'safety' => 'risky',
                                    ];
                                } else {
                                    $errors[$tablename][] = (object) [
                                        'desc' => "column '$fieldname' should allow NULL ($dbfield->meta_type)",
                                        'type' => 'changedcolumns',
                                        'issue' => 'null',
                                        'field' => $field,
                                        'dbfield' => $dbfield,
                                        'status' => result::WARNING,
                                        'safety' => 'safe',
                                    ];
                                }
                            }
                            switch ($dbtype) {
                                case XMLDB_TYPE_TEXT:
                                case XMLDB_TYPE_BINARY:
                                    // No length check necessary - there is one size only now.
                                    break;

                                case XMLDB_TYPE_NUMBER:
                                    $lengthmismatch = $field->getLength() != $dbfield->max_length;
                                    $decimalmismatch = $field->getDecimals() != $dbfield->scale;
                                    // Do not use floats in any new code, they are deprecated in XMLDB editor!
                                    if ($field->getType() != XMLDB_TYPE_FLOAT && ($lengthmismatch || $decimalmismatch)) {
                                        $size = "({$field->getLength()},{$field->getDecimals()})";
                                        $dbsize = "($dbfield->max_length,$dbfield->scale)";
                                        $safety = 'safe';
                                        if ($field->getDecimals() < $dbfield->scale) {
                                            $safety = 'manual';
                                        } else if ($field->getLength() < $dbfield->max_length ||
                                                $field->getDecimals() > $dbfield->scale) {
                                            $safety = 'risky';
                                        }
                                        $errors[$tablename][] = (object) [
                                            'desc' => "column '$fieldname' size is $dbsize, expected $size ($dbfield->meta_type)",
                                            'type' => 'changedcolumns',
                                            'issue' => 'length',
                                            'field' => $field,
                                            'dbfield' => $dbfield,
                                            'status' => result::WARNING,
                                            'safety' => $safety,
                                        ];
                                    }
                                    break;

                                case XMLDB_TYPE_CHAR:
                                    // This is not critical, but they should ideally match.
                                    if ($field->getLength() != $dbfield->max_length) {
                                        $errors[$tablename][] = (object) [
                                            'desc' => "column '$fieldname' length is $dbfield->max_length, " .
                                                "expected {$field->getLength()} ($dbfield->meta_type)",
                                            'type' => 'changedcolumns',
                                            'issue' => 'length',
                                            'field' => $field,
                                            'dbfield' => $dbfield,
                                            'status' => result::WARNING,
                                            'safety' => $field->getLength() > $dbfield->max_length ? 'safe' : 'risky',
                                        ];
                                    }
                                    break;

                                case XMLDB_TYPE_INTEGER:
                                    // Integers may be bigger in some DBs.
                                    $length = $field->getLength();
                                    if ($length > 18) {
                                        // Integers are not supposed to be bigger than 18.
                                        $length = 18;
                                    }
                                    if ($length > $dbfield->max_length) {
                                        $errors[$tablename][] = (object) [
                                            'desc' => "column '$fieldname' length is $dbfield->max_length, " .
                                                "expected at least {$field->getLength()} ($dbfield->meta_type)",
                                            'type' => 'changedcolumns',
                                            'issue' => 'length',
                                            'field' => $field,
                                            'dbfield' => $dbfield,
                                            'status' => result::WARNING,
                                            'safety' => 'safe',
                                        ];
                                    }
                                    break;

                                case XMLDB_TYPE_TIMESTAMP:
                                    $errors[$tablename][] = (object) [
                                        'desc' => "column '$fieldname' is a timestamp, this type is not supported ($dbfield->meta_type)",
                                        'type' => 'changedcolumns',
                                        'issue' => 'type',
                                        'field' => $field,
                                        'dbfield' => $dbfield,
                                        'status' => result::WARNING,
                                        'safety' => 'risky',
                                    ];
                                    continue 2;

                                case XMLDB_TYPE_DATETIME:
                                    $errors[$tablename][] = (object) [
                                        'desc' => "column '$fieldname' is a datetime, this type is not supported ($dbfield->meta_type)",
                                        'type' => 'changedcolumns',
                                        'issue' => 'type',
                                        'field' => $field,
                                        'dbfield' => $dbfield,
                                        'status' => result::WARNING,
                                        'safety' => 'risky',
                                    ];
                                    continue 2;

                                default:
                                    // Report all other unsupported types as problems.
                                    $errors[$tablename][] = (object) [
                                        'desc' => "column '$fieldname' has unknown type ($dbfield->meta_type)",
                                        'type' => 'changedcolumns',
                                        'issue' => 'type',
                                        'field' => $field,
                                        'dbfield' => $dbfield,
                                        'status' => result::WARNING,
                                        'safety' => 'risky',
                                    ];
                                    continue 2;

                            }

                            // Note: The empty string defaults are a bit messy...
                            // TODO: Check to make sure this doesn't break floats in mysql like MDL-63252.
                            $default = $this->generator->getDefault($field);
                            if ($default !== null) {
                                $default = (string) $default;
                            }
                            $dbdefault = $dbfield->has_default ? $dbfield->default_value : null;
                            if ($default !== $dbfield->default_value) {
                                $default = is_null($default) ? 'NULL' : $default;
                                $dbdefault = is_null($dbfield->default_value) ? 'NULL' : $dbfield->default_value;
                                $errors[$tablename][] = (object) [
                                    'desc' => "column '$fieldname' has default '$dbdefault', " .
                                        "expected '$default' ($dbfield->meta_type)",
                                    'type' => 'changedcolumns',
                                    'issue' => 'default',
                                    'field' => $field,
                                    'dbfield' => $dbfield,
                                    'status' => result::WARNING,
                                    'safety' => 'safe',
                                ];
                            }
                        }
                    }
                }
                unset($dbfields[$fieldname]);
            }

            // Check for missing indexes/keys.
            if ($options['missingindexes']) {
                // Check the foreign keys.
                if ($keys = $table->getKeys()) {
                    foreach ($keys as $key) {
                        // Primary keys are skipped.
                        if ($key->getType() == XMLDB_KEY_PRIMARY) {
                            continue;
                        }

                        $keyname = $key->getName();

                        // Create the interim index.
                        $index = new xmldb_index($keyname);
                        $index->setFields($key->getFields());
                        switch ($key->getType()) {
                            case XMLDB_KEY_UNIQUE:
                            case XMLDB_KEY_FOREIGN_UNIQUE:
                                $index->setUnique(true);
                                break;
                            case XMLDB_KEY_FOREIGN:
                                $index->setUnique(false);
                                break;
                        }
                        if (!$this->index_exists($table, $index)) {
                            $errors[$tablename][] = (object) [
                                'desc' => $this->get_missing_index_error($table, $index, $keyname),
                                'type' => 'missingindexes',
                                'index' => $index,
                                'status' => result::WARNING,
                                'safety' => 'safe',
                            ];
                        } else {
                            $this->remove_index_from_dbindex($dbindexes, $index);
                        }
                    }
                }

                // Check the indexes.
                if ($indexes = $table->getIndexes()) {
                    foreach ($indexes as $index) {
                        if (!$this->index_exists($table, $index)) {
                            $errors[$tablename][] = (object) [
                                'desc' => $this->get_missing_index_error($table, $index, $index->getName()),
                                'type' => 'missingindexes',
                                'index' => $index,
                                'status' => result::WARNING,
                                'safety' => 'safe',
                            ];
                        } else {
                            $this->remove_index_from_dbindex($dbindexes, $index);
                        }
                    }
                }
            }

            // Check if we should show the extra indexes.
            if ($options['extraindexes']) {
                // Hack - skip for table 'search_simpledb_index' as this plugin adds indexes dynamically on install
                // which are not included in install.xml. See search/engine/simpledb/db/install.php.
                if ($tablename != 'search_simpledb_index') {
                    foreach ($dbindexes as $indexname => $index) {
                        $indextype = $index['unique'] ? XMLDB_INDEX_UNIQUE : XMLDB_INDEX_NOTUNIQUE;
                        $errors[$tablename][] = (object) [
                            'desc' => "Unexpected index '$indexname'",
                            'type' => 'extraindexes',
                            'index' => new xmldb_index($indexname, $indextype, $index['columns']),
                            'status' => result::INFO,
                            'safety' => 'manual',
                        ];
                    }
                }
            }

            // Check for extra columns (indicates unsupported hacks) - modify install.xml if you want to pass validation.
            foreach ($dbfields as $fieldname => $dbfield) {
                if ($options['extracolumns']) {
                    $errors[$tablename][] = (object) [
                        'desc' => "column '$fieldname' is not expected ($dbfield->meta_type)",
                        'type' => 'extracolumns',
                        'field' => new xmldb_field($fieldname),
                        'status' => result::INFO,
                        'safety' => 'manual',
                    ];
                }
            }
            unset($dbtables[$tablename]);
        }

        if ($options['extratables']) {
            // Look for unsupported tables - local custom tables should be in /local/xxxx/db/install.xml file.
            // If there is no prefix, we can not say if table is ours, sorry.
            if ($this->generator->prefix !== '') {
                foreach ($dbtables as $tablename => $unused) {
                    if (strpos($tablename, 'pma_') === 0) {
                        // Ignore phpmyadmin tables.
                        continue;
                    }
                    if (strpos($tablename, 'test') === 0) {
                        // Legacy simple test db tables need to be eventually removed,
                        // report them as problems!
                        $errors[$tablename][] = (object) [
                            'desc' => 'table is not expected (it may be a leftover after Simpletest unit tests)',
                            'type' => 'extratables',
                            'status' => result::INFO,
                            'safety' => 'safe',
                        ];
                    } else {
                        $errors[$tablename][] = (object) [
                            'desc' => 'table is not expected',
                            'type' => 'extratables',
                            'status' => result::INFO,
                            'safety' => 'manual',
                        ];
                    }
                }
            }
        }

        if (!$fulldetails) {
            return array_map(fn($rows) => array_column($rows, 'desc'), $errors);
        }

        return $errors;
    }

    /**
     * Attempts to fix database schema issues based on provided errors and safety levels.
     *
     * @param array $errors Set of errors returned by check_database_schema()
     * @param array $levels Safety levels specifying which types of fixes to attempt
     * @return void
     */
    public function fix_database_schema(array $errors, array $levels): void {
        // The error check doesn't use cache, while some transformations do.
        // Reset cache to make sure we have the latest data.
        $this->mdb->reset_caches();
        $this->add_missing_definitions($errors, $levels);
        $this->align_column_definitions($errors, $levels);
        $this->drop_extra_definitions($errors, $levels);
    }

    /**
     * Filters a list of schema errors to include only those of a specific type.
     *
     * @param array $errors Set of errors returned by check_database_schema()
     * @param string $type The error type to filter by
     * @return array
     */
    protected function filter_errors(array $errors, string $type): array {
        return array_filter(
            array_map(
                fn($group) => array_filter($group, fn($e) => str_contains($e->type, $type)),
                $errors,
            )
        );
    }

    /**
     * Create all schema elements that are missing.
     *
     * @param array $errors Set of errors returned by check_database_schema()
     * @param array $levels Safety levels specifying which types of fixes to attempt
     * @return void
     */
    protected function add_missing_definitions(array $errors, array $levels): void {
        $errors = $this->filter_errors($errors, 'missing');
        foreach ($errors as $tablename => $details) {
            $table = new xmldb_table($tablename);
            foreach ($details as $error) {
                if (!in_array($error->safety, $levels)) {
                    continue;
                }
                if ($error->type == 'missingtables') {
                    if (!$this->table_exists($error->table)) {
                        mtrace(" * $tablename Creating missing table");
                        $this->create_table($error->table);
                    }
                }

                if ($error->type == 'missingcolumns') {
                    if (!$this->field_exists($table, $error->field)) {
                        $prefix = " * $tablename:{$error->field->getName()}";
                        mtrace("$prefix Creating missing column");
                        $this->add_field($table, $error->field);
                    }
                }
            }
        }

        // Add indexes in a second pass after adding all missing tables/columns.
        foreach ($errors as $tablename => $details) {
            $table = new xmldb_table($tablename);
            foreach ($details as $error) {
                if (!in_array($error->safety, $levels)) {
                    continue;
                }
                if ($error->type == 'missingindexes') {
                    if (!$this->index_exists($table, $error->index)) {
                        mtrace(" * $tablename Creating missing index " . $error->index->getName());
                        $this->add_index($table, $error->index);
                    }
                }
            }
        }
    }

    /**
     * Aligns existing database column definitions to match the expected schema.
     *
     * @param array $errors Set of errors returned by check_database_schema()
     * @param array $levels Safety levels specifying which types of fixes to attempt
     * @return void
     */
    protected function align_column_definitions(array $errors, array $levels): void {
        $errors = $this->group_errors_by_column($errors);

        foreach ($errors as $tablename => $columns) {
            $table = new xmldb_table($tablename);
            foreach ($columns as $columnname => $error) {
                if (!in_array($error->safety, $levels)) {
                    continue;
                }
                if ($error->safety === 'risky') {
                    // Run additional queries to get a more accurate safety level.
                    $safety = $this->check_align_column_safety($tablename, $error, $levels, false);
                    if (!in_array($safety, $levels)) {
                        continue;
                    }
                }
                $prefix = " * $tablename:$columnname";
                if ($this->field_exists($table, $error->field)) {
                    try {
                        // Temporarily drop indexes to modify this column.
                        $indexes = $this->drop_column_indexes($table, $error->field);

                        // Change field types fixes multiple issues at once.
                        mtrace("$prefix Updating column to match XMLDB definition");
                        $this->change_field_type($table, $error->field);

                        // Not all databases change default with field type.
                        if (in_array('default', $error->issues)) {
                            $this->change_field_default($table, $error->field);
                        }

                        // Restore dropped indexes.
                        if ($indexes) {
                            $this->restore_column_indexes($table, $error->field, $indexes);
                            $indexes = [];
                        }
                    } catch (Throwable $e) {
                        // TODO: Check what types of errors can be thrown and make this more specific.
                        mtrace("$prefix Could not update column: " . $e->getMessage());
                        if (!empty($indexes)) {
                            $this->restore_column_indexes($table, $error->field, $indexes);
                            $indexes = [];
                        }
                    }
                }
            }
        }
    }

    /**
     * Removes extra database schema definitions that are not expected.
     *
     * @param array $errors Set of errors returned by check_database_schema()
     * @param array $levels Safety levels specifying which types of fixes to attempt
     * @return void
     */
    protected function drop_extra_definitions(array $errors, array $levels): void {
        $errors = $this->filter_errors($errors, 'extra');
        foreach ($errors as $tablename => $details) {
            $table = new xmldb_table($tablename);
            foreach ($details as $error) {
                if (!in_array($error->safety, $levels)) {
                    continue;
                }

                if ($error->type == 'extratables') {
                    if ($this->table_exists($table)) {
                        // There may be edge cases with manual foreign keys.
                        mtrace(" * $tablename Dropping extra table");
                        $this->drop_table($table);
                    }
                }

                if ($error->type == 'extracolumns') {
                    if ($this->field_exists($table, $error->field)) {
                        // There may be edge cases with manual foreign keys.
                        $this->drop_column_indexes($table, $error->field);
                        $prefix = " * $tablename:{$error->field->getName()}";
                        mtrace("$prefix Dropping extra column");
                        $this->drop_field($table, $error->field);
                    }
                }

                if ($error->type == 'extraindexes') {
                    if ($this->index_exists($table, $error->index)) {
                        mtrace(" * $tablename Dropping extra index " . $error->index->getName());
                        $this->drop_index($table, $error->index);
                    }
                }
            }
        }
    }

    /**
     * Drops indexes for the specified field in the provided table.
     *
     * @param xmldb_table $table
     * @param xmldb_field $field
     * @return array List of xmldb_index that were dropped
     */
    protected function drop_column_indexes(xmldb_table $table, xmldb_field $field): array {
        $prefix = " * {$table->getName()}:{$field->getName()}";
        $dbindexes = $this->mdb->get_indexes($table->getName());
        $indexes = [];

        // Get all indexes for this column.
        foreach ($dbindexes as $indexname => $index) {
            $columns = $index['columns'];
            if (in_array($field->getName(), $columns)) {
                $type = !empty($index['unique']) ? XMLDB_INDEX_UNIQUE : XMLDB_INDEX_NOTUNIQUE;
                $indexes[] = new xmldb_index($indexname, $type, $columns);
            }
        }

        // Drop indexes.
        foreach ($indexes as $index) {
            if ($this->index_exists($table, $index)) {
                mtrace("$prefix Dropping index " . $index->getName());
                $this->drop_index($table, $index);
            }
        }
        return $indexes;
    }

    /**
     * Restores indexes for the specified field in the provided table.
     *
     * @param xmldb_table $table
     * @param xmldb_field $field
     * @param array $indexes List of xmldb_index to restore
     * @return void
     */
    protected function restore_column_indexes(xmldb_table $table, xmldb_field $field, array $indexes): void {
        $prefix = " * {$table->getName()}:{$field->getName()}";
        foreach ($indexes as $index) {
            if (!$this->index_exists($table, $index)) {
                mtrace("$prefix Restoring index " . $index->getName());
                $this->add_index($table, $index);
            }
        }
    }

    /**
     * Groups a set of column alignment errors by column.
     * These errors are generally processed together to avoid issues with partial alignment.
     *
     * @param array $errors Set of errors returned by check_database_schema()
     * @return array Set of errors combined by column
     */
    protected function group_errors_by_column(array $errors): array {
        $errors = $this->filter_errors($errors, 'changedcolumns');

        // Treat risky as higher than manual as this will be checked.
        $safetylevel = [
            'safe' => 1,
            'manual' => 2,
            'risky' => 3,
            'unsafe' => 4,
        ];

        $columnerrors = [];
        foreach ($errors as $tablename => $details) {
            // Combine column errors so that they are only handled once.
            $columns = array_reduce($details, function ($columns, $error) use ($safetylevel) {
                $column = $error->field->getName();
                if (!isset($columns[$column])) {
                    // Most required fields should be the same for each column.
                    $columns[$column] = clone $error;
                    unset($columns[$column]->desc, $columns[$column]->issue);
                }

                // Keep the highest safety level.
                if ($safetylevel[$error->safety] > $safetylevel[$columns[$column]->safety]) {
                    $columns[$column]->safety = $error->safety;
                }

                // Add all issues to a new field.
                $columns[$column]->issues[] = $error->issue;
                return $columns;
            }, []);

            $columnerrors[$tablename] = $columns;
        }
        return $columnerrors;
    }

    /**
     * Checks whether it is safe to align a column definition to resolve the provided error.
     *
     * @param string $tablename The name of the table containing the column
     * @param stdClass $error The schema error details for a specific column
     * @param array $levels Safety levels specifying which types of fixes to attempt
     * @param bool $forcefix Run sql to resolve the data issues where possible
     * @return string Estimated safety level
     */
    public function check_align_column_safety(string $tablename, stdClass $error, array $levels, bool $forcefix): string {
        global $DB;

        // We only need to check issues marked as risky.
        if ($error->safety !== 'risky') {
            return $error->safety;
        }

        // Reset the safety level and increase when issues are found.
        $error->safety = 'safe';
        $field = $error->field;
        $dbfield = $error->dbfield;
        $columnname = $field->getName();
        $prefix = " * $tablename:$columnname";

        // If changing to not null, check if there are any nulls.
        if ($field->getNotNull() && !$dbfield->not_null) {
            if ($DB->record_exists($tablename, [$columnname => null])) {
                // Unsafe as we have null values.
                if ($forcefix) {
                    mtrace("$prefix Converting null values to default");
                    $DB->set_field($tablename, $columnname, $this->generator->getDefault($field), [$columnname => null]);
                } else {
                    mtrace("$prefix Cannot update the column to not null as it contains nulls");
                    $error->safety = 'unsafe';
                    return $error->safety;
                }
            }
        }

        // TODO: Add type change checks.
        if (in_array('type', $error->issues)) {
            if (!in_array('unsafe', $levels)) {
                mtrace("$prefix Checks for type changes are not supported, you can run this using 'unsafe' safety.");
            }
            $error->safety = 'unsafe';
            return $error->safety;
        }

        // Check for size changes for all columns types besides text.
        if ($field->getType() != XMLDB_TYPE_TEXT) {
            $length = $field->getLength();
            $decimals = $field->getDecimals();
            $decimalincrease = 0;

            // Check precision.
            if (isset($decimals) && $decimals < $dbfield->scale) {
                // Decreasing precision changes data. Update safety to 'manual' and continue processing.
                if (!in_array('manual', $levels)) {
                    mtrace("$prefix Updating the column will reduce precision which requires 'manual' safety");
                }
                $error->safety = 'manual';
            } else if (isset($decimals) && $decimals > $dbfield->scale) {
                $decimalincrease = $decimals - $error->dbfield->scale;
            }

            // If increasing, it should be fine if there are no complications with indexes.
            if ($length < $error->dbfield->max_length || $decimalincrease) {
                $adjustedlength = $length - $decimalincrease;
                if ($DB->record_exists_select($tablename, $DB->sql_length('?') . ' > ?', [$columnname, $adjustedlength])) {
                    // Unsafe - would need to remove or trim these values.
                    mtrace("$prefix Cannot update as it contains data larger than the new length");
                    $error->safety = 'unsafe';
                    return $error->safety;
                }
            }
        }

        return $error->safety;
    }

    /**
     * Evaluates errors marked as 'risky' to determine if they are safe or unsafe.
     * This performs additional queries to check whether the column data fits the schema,
     * and updates the error safety flag.
     *
     * @param array $errors Set of errors returned by check_database_schema()
     * @param bool $forcefix Run SQL to resolve the data issues where possible
     * @return void
     */
    protected function evaluate_risky_errors(array $errors, bool $forcefix): void {
        $columnerrors = $this->group_errors_by_column($errors);
        foreach ($columnerrors as $tablename => $columns) {
            foreach ($columns as $error) {
                if ($error->safety !== 'risky') {
                    continue;
                }

                $this->check_align_column_safety($tablename, $error, [], $forcefix);
                if ($error->safety !== 'risky') {
                    $prefix = " * $tablename:{$error->field->getName()}";
                    mtrace("$prefix is safe to fix");
                }

                // Update the safety flag of the original errors.
                if (isset($errors[$tablename])) {
                    foreach ($errors[$tablename] as $details) {
                        if ($details->type === 'changedcolumns' ) {
                            $details->safety = $error->safety;
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns a string describing the missing index error.
     *
     * @param xmldb_table $table
     * @param xmldb_index $index
     * @param string $indexname
     * @return string
     */
    private function get_missing_index_error(xmldb_table $table, xmldb_index $index, string $indexname): string {
        $sqlarr = $this->generator->getAddIndexSQL($table, $index);
        $sqlarr = $this->generator->getEndedStatements($sqlarr);
        $sqltoadd = reset($sqlarr);

        return "Missing index '" . $indexname . "' " . "(" . $index->readableInfo() . "). \n" . $sqltoadd;
    }

    /**
     * Removes an index from the array $dbindexes if it is found.
     *
     * @param array $dbindexes
     * @param xmldb_index $index
     */
    private function remove_index_from_dbindex(array &$dbindexes, xmldb_index $index) {
        foreach ($dbindexes as $key => $dbindex) {
            if ($dbindex['columns'] == $index->getFields()) {
                unset($dbindexes[$key]);
            }
        }
    }
}
