<?php
//----------------------------------------------------------------------------------------------------------------------
/**
 * PhpStratum
 *
 * @copyright 2005-2015 Paul Water / Set Based IT Consultancy (https://www.setbased.nl)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link
 */
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\Stratum\MySql\Command;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SetBased\Exception\RuntimeException;
use SetBased\Stratum\MySql\MetadataDataLayer as DataLayer;
use SetBased\Stratum\MySql\RoutineLoaderHelper;
use SetBased\Stratum\NameMangler\NameMangler;
use SetBased\Stratum\Style\StratumStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

//----------------------------------------------------------------------------------------------------------------------
/**
 * Command for loading stored routines into a MySQL instance from pseudo SQL files.
 */
class RoutineLoaderCommand extends MySqlCommand
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The default character set under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $myCharacterSet;

  /**
   * The default collate under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $myCollate;

  /**
   * Name of the class that contains all constants.
   *
   * @var string
   */
  private $myConstantClassName;

  /**
   * An array with source filenames that are not loaded into MySQL.
   *
   * @var array
   */
  private $myErrorFileNames = [];

  /**
   * Class name for mangling routine and parameter names.
   *
   * @var string
   */
  private $myNameMangler;

  /**
   * The metadata of all stored routines. Note: this data is stored in the metadata file and is generated by PhpStratum.
   *
   * @var array
   */
  private $myPhpStratumMetadata;

  /**
   * The filename of the file with the metadata of all stored routines.
   *
   * @var string
   */
  private $myPhpStratumMetadataFilename;

  /**
   * Old metadata of all stored routines. Note: this data comes from information_schema.ROUTINES.
   *
   * @var array
   */
  private $myRdbmsOldMetadata;

  /**
   * A map from placeholders to their actual values.
   *
   * @var array
   */
  private $myReplacePairs = [];

  /**
   * Path where source files can be found.
   *
   * @var string
   */
  private $mySourceDirectory;

  /**
   * The extension of the source files.
   *
   * @var string
   */
  private $mySourceFileExtension;

  /**
   * All sources with stored routines. Each element is an array with the following keys:
   * <ul>
   * <li> path_name    The path the source file.
   * <li> routine_name The name of the routine (equals the basename of the path).
   * <li> method_name  The name of the method in the data layer for the wrapper method of the stored routine.
   * </ul>
   *
   * @var array[]
   */
  private $mySources = [];

  /**
   * The SQL mode under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $mySqlMode;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * {@inheritdoc}
   */
  protected function configure()
  {
    $this->setName('loader')
         ->setDescription('Generates the routine wrapper class');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $this->io = new StratumStyle($input, $output);

    $this->io->title('Loader');

    $configFileName = $input->getArgument('config file');
    $file_names     = $input->getArgument('sources');
    $settings       = $this->readConfigFile($configFileName);

    $this->connect($settings);

    if (empty($file_names))
    {
      $this->loadAll();
    }
    else
    {
      $this->loadList($file_names);
    }

    $this->logOverviewErrors();

    $this->disconnect();

    return ($this->myErrorFileNames) ? 1 : 0;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads parameters from the configuration file.
   *
   * @param string $configFilename
   *
   * @return array
   */
  protected function readConfigFile($configFilename)
  {
    $settings = parse_ini_file($configFilename, true);

    $this->myPhpStratumMetadataFilename = self::getSetting($settings, true, 'loader', 'metadata');
    $this->mySourceDirectory            = self::getSetting($settings, true, 'loader', 'source_directory');
    $this->mySourceFileExtension        = self::getSetting($settings, true, 'loader', 'extension');
    $this->mySqlMode                    = self::getSetting($settings, true, 'loader', 'sql_mode');
    $this->myCharacterSet               = self::getSetting($settings, true, 'loader', 'character_set');
    $this->myCollate                    = self::getSetting($settings, true, 'loader', 'collate');
    $this->myConstantClassName          = self::getSetting($settings, false, 'constants', 'class');
    $this->myNameMangler                = self::getSetting($settings, false, 'wrapper', 'mangler_class');

    return $settings;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Detects stored routines that would result in duplicate wrapper method name.
   */
  private function detectNameConflicts()
  {
    // Get same method names from array
    list($sources_by_path, $sources_by_method) = $this->getDuplicates();

    // Add every not unique method name to myErrorFileNames
    foreach ($sources_by_path as $source)
    {
      $this->myErrorFileNames[] = $source['path_name'];
    }

    // Log the sources files with duplicate method names.
    foreach ($sources_by_method as $method => $sources)
    {
      $tmp = [];
      foreach ($sources as $source)
      {
        $tmp[] = $source['path_name'];
      }

      $this->io->error(sprintf("The following source files would result wrapper methods with equal name '%s'",
                               $method));
      $this->io->listing($tmp);
    }

    // Remove duplicates from mySources.
    foreach ($this->mySources as $i => $source)
    {
      if (isset($sources_by_path[$source['path_name']]))
      {
        unset($this->mySources[$i]);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Drops obsolete stored routines (i.e. stored routines that exits in the current schema but for which we don't have
   * a source file).
   */
  private function dropObsoleteRoutines()
  {
    // Make a lookup table from routine name to source.
    $lookup = [];
    foreach ($this->mySources as $source)
    {
      $lookup[$source['routine_name']] = $source;
    }

    // Drop all routines not longer in sources.
    foreach ($this->myRdbmsOldMetadata as $old_routine)
    {
      if (!isset($lookup[$old_routine['routine_name']]))
      {
        $this->io->logInfo('Dropping %s <dbo>%s</dbo>',
                           strtolower($old_routine['routine_type']),
                           $old_routine['routine_name']);

        DataLayer::dropRoutine($old_routine['routine_type'], $old_routine['routine_name']);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Searches recursively for all source files in a directory.
   *
   * @param string|null $sourceDir The directory.
   */
  private function findSourceFiles($sourceDir = null)
  {
    if ($sourceDir===null) $sourceDir = $this->mySourceDirectory;

    $directory = new RecursiveDirectoryIterator($sourceDir);
    $directory->setFlags(RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
    $files = new RecursiveIteratorIterator($directory);
    foreach ($files as $full_path => $file)
    {
      // If the file is a source file with stored routine add it to my sources.
      if ($file->isFile() && '.'.$file->getExtension()==$this->mySourceFileExtension)
      {
        $this->mySources[] = ['path_name'    => $full_path,
                              'routine_name' => $file->getBasename($this->mySourceFileExtension),
                              'method_name'  => $this->methodName($file->getFilename())];
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Finds all source files that actually exists from a list of file names.
   *
   * @param string[] $fileNames The list of file names.
   */
  private function findSourceFilesFromList($fileNames)
  {
    foreach ($fileNames as $psql_filename)
    {
      if (!file_exists($psql_filename))
      {
        $this->io->error(sprintf("File not exists: '%s'", $psql_filename));
        $this->myErrorFileNames[] = $psql_filename;
      }
      else
      {
        $extension = '.'.pathinfo($psql_filename, PATHINFO_EXTENSION);
        if ($extension==$this->mySourceFileExtension)
        {
          $routine_name      = pathinfo($psql_filename, PATHINFO_FILENAME);
          $this->mySources[] = ['path_name'    => $psql_filename,
                                'routine_name' => $routine_name,
                                'method_name'  => $this->methodName($routine_name)];
        }
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Selects schema, table, column names and the column type from MySQL and saves them as replace pairs.
   */
  private function getColumnTypes()
  {
    $rows = DataLayer::getAllTableColumns();
    foreach ($rows as $row)
    {
      $key = '@'.$row['table_name'].'.'.$row['column_name'].'%type@';
      $key = strtoupper($key);

      $value = $row['column_type'];
      if (isset($row['character_set_name'])) $value .= ' character set '.$row['character_set_name'];

      $this->myReplacePairs[$key] = $value;
    }

    $this->io->text(sprintf('Selected %d column types for substitution', sizeof($rows)));
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads constants set the PHP configuration file and  adds them to the replace pairs.
   */
  private function getConstants()
  {
    // If myTargetConfigFilename is not set return immediately.
    if (!isset($this->myConstantClassName)) return;

    $reflection = new \ReflectionClass($this->myConstantClassName);

    $constants = $reflection->getConstants();
    foreach ($constants as $name => $value)
    {
      if (!is_numeric($value)) $value = "'".$value."'";

      $this->myReplacePairs['@'.$name.'@'] = $value;
    }

    $this->io->text(sprintf('Read %d constants for substitution from <fso>%s</fso>',
                            sizeof($constants),
                            OutputFormatter::escape($reflection->getFileName())));
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Gets the SQL mode in the order as preferred by MySQL.
   */
  private function getCorrectSqlMode()
  {
    $this->mySqlMode = DataLayer::getCorrectSqlMode($this->mySqlMode);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns all elements in {@link $sources} with duplicate method names.
   *
   * @return array[]
   */
  private function getDuplicates()
  {
    // First pass make lookup table by method_name.
    $lookup = [];
    foreach ($this->mySources as $source)
    {
      if (isset($source['method_name']))
      {
        if (!isset($lookup[$source['method_name']]))
        {
          $lookup[$source['method_name']] = [];
        }

        $lookup[$source['method_name']][] = $source;
      }
    }

    // Second pass find duplicate sources.
    $duplicates_sources = [];
    $duplicates_methods = [];
    foreach ($this->mySources as $source)
    {
      if (sizeof($lookup[$source['method_name']])>1)
      {
        $duplicates_sources[$source['path_name']]   = $source;
        $duplicates_methods[$source['method_name']] = $lookup[$source['method_name']];
      }
    }

    return [$duplicates_sources, $duplicates_methods];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Retrieves information about all stored routines in the current schema.
   */
  private function getOldStoredRoutinesInfo()
  {
    $this->myRdbmsOldMetadata = [];

    $routines = DataLayer::getRoutines();
    foreach ($routines as $routine)
    {
      $this->myRdbmsOldMetadata[$routine['routine_name']] = $routine;
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads all stored routines into MySQL.
   */
  private function loadAll()
  {
    $this->findSourceFiles();
    $this->detectNameConflicts();
    $this->getColumnTypes();
    $this->readStoredRoutineMetadata();
    $this->getConstants();
    $this->getOldStoredRoutinesInfo();
    $this->getCorrectSqlMode();

    $this->loadStoredRoutines();

    // Drop obsolete stored routines.
    $this->dropObsoleteRoutines();

    // Remove metadata of stored routines that have been removed.
    $this->removeObsoleteMetadata();

    $this->io->writeln('');

    // Write the metadata to file.
    $this->writeStoredRoutineMetadata();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads all stored routines in a list into MySQL.
   *
   * @param string[] $fileNames The list of files to be loaded.
   */
  private function loadList($fileNames)
  {
    $this->findSourceFilesFromList($fileNames);
    $this->detectNameConflicts();
    $this->getColumnTypes();
    $this->readStoredRoutineMetadata();
    $this->getConstants();
    $this->getOldStoredRoutinesInfo();
    $this->getCorrectSqlMode();

    $this->loadStoredRoutines();

    // Write the metadata to file.
    $this->writeStoredRoutineMetadata();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads all stored routines.
   */
  private function loadStoredRoutines()
  {
    // Log an empty line.
    $this->io->writeln('');

    // Sort the sources by routine name.
    usort($this->mySources, function ($a, $b)
    {
      return strcmp($a['routine_name'], $b['routine_name']);
    });

    // Process all sources.
    foreach ($this->mySources as $filename)
    {
      $routine_name = $filename['routine_name'];

      $helper = new RoutineLoaderHelper($this->io,
                                        $filename['path_name'],
                                        $this->mySourceFileExtension,
                                        isset($this->myPhpStratumMetadata[$routine_name]) ? $this->myPhpStratumMetadata[$routine_name] : null,
                                        $this->myReplacePairs,
                                        isset($this->myRdbmsOldMetadata[$routine_name]) ? $this->myRdbmsOldMetadata[$routine_name] : null,
                                        $this->mySqlMode,
                                        $this->myCharacterSet,
                                        $this->myCollate);

      $meta_data = $helper->loadStoredRoutine();
      if ($meta_data===false)
      {
        // An error occurred during the loading of the stored routine.
        $this->myErrorFileNames[] = $filename['path_name'];
        unset($this->myPhpStratumMetadata[$routine_name]);
      }
      else
      {
        // Stored routine is successfully loaded.
        $this->myPhpStratumMetadata[$routine_name] = $meta_data;
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Logs the source files that were not successfully loaded into MySQL.
   */
  private function logOverviewErrors()
  {
    if (!empty($this->myErrorFileNames))
    {
      $this->io->warning('Routines in the files below are not loaded:');
      $this->io->listing($this->myErrorFileNames);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the method name in the wrapper for a stored routine. Returns null when name mangler is not set.
   *
   * @param string $routineName The name of the routine.
   *
   * @return null|string
   */
  private function methodName($routineName)
  {
    if ($this->myNameMangler!==null)
    {
      /** @var NameMangler $mangler */
      $mangler = $this->myNameMangler;

      return $mangler::getMethodName($routineName);
    }

    return null;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads the metadata of stored routines from the metadata file.
   */
  private function readStoredRoutineMetadata()
  {
    if (file_exists($this->myPhpStratumMetadataFilename))
    {
      $this->myPhpStratumMetadata = (array)json_decode(file_get_contents($this->myPhpStratumMetadataFilename), true);
      if (json_last_error()!=JSON_ERROR_NONE)
      {
        throw new RuntimeException("Error decoding JSON: '%s'.", json_last_error_msg());
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Removes obsolete entries from the metadata of all stored routines.
   */
  private function removeObsoleteMetadata()
  {
    // 1 pass through $mySources make new array with routine_name is key.
    $clean = [];
    foreach ($this->mySources as $source)
    {
      $routine_name = $source['routine_name'];
      if (isset($this->myPhpStratumMetadata[$routine_name]))
      {
        $clean[$routine_name] = $this->myPhpStratumMetadata[$routine_name];
      }
    }

    $this->myPhpStratumMetadata = $clean;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Writes the metadata of all stored routines to the metadata file.
   */
  private function writeStoredRoutineMetadata()
  {
    $json_data = json_encode($this->myPhpStratumMetadata, JSON_PRETTY_PRINT);
    if (json_last_error()!=JSON_ERROR_NONE)
    {
      throw new RuntimeException("Error of encoding to JSON: '%s'.", json_last_error_msg());
    }

    // Save the metadata.
    $this->writeTwoPhases($this->myPhpStratumMetadataFilename, $json_data);
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
