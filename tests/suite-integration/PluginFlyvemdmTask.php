<?php
/**
 * LICENSE
 *
 * Copyright © 2016-2018 Teclib'
 * Copyright © 2010-2018 by the FusionInventory Development Team.
 *
 * This file is part of Flyve MDM Plugin for GLPI.
 *
 * Flyve MDM Plugin for GLPI is a subproject of Flyve MDM. Flyve MDM is a mobile
 * device management software.
 *
 * Flyve MDM Plugin for GLPI is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * Flyve MDM Plugin for GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 * You should have received a copy of the GNU Affero General Public License
 * along with Flyve MDM Plugin for GLPI. If not, see http://www.gnu.org/licenses/.
 * ------------------------------------------------------------------------------
 * @author    Thierry Bugier
 * @copyright Copyright © 2018 Teclib
 * @license   AGPLv3+ http://www.gnu.org/licenses/agpl.txt
 * @link      https://github.com/flyve-mdm/glpi-plugin
 * @link      https://flyve-mdm.com/
 * ------------------------------------------------------------------------------
 */

namespace tests\units;

use Flyvemdm\Tests\TestingCommonTools;
use Flyvemdm\Tests\CommonTestCase;

class PluginFlyvemdmTask extends CommonTestCase {

   public function beforeTestMethod($method) {
      parent::beforeTestMethod($method);
      $this->setupGLPIFramework();
      $this->login('glpi', 'glpi');
   }

   public function afterTestMethod($method) {
      parent::afterTestMethod($method);
      \Session::destroy();
   }

   /**
    * @tags testApplyPolicy
    */
   public function testApplyPolicy() {
      // Create an agent
      $guestEmail = $this->getUniqueEmail();
      $invitation = $this->createInvitation($guestEmail);
      $this->variable($invitation)->isNotNull();
      $user = new \User();
      $user->getFromDB($invitation->getField(\User::getForeignKeyField()));
      $serial = $this->getUniqueString();
      $agent = $this->enrollFromInvitation(
         $user, [
            'entities_id'       => $_SESSION['glpiactive_entity'],
            '_email'            => $guestEmail,
            '_invitation_token' => $invitation->getField('invitation_token'),
            '_serial'           => $serial,
            'csr'               => '',
            'firstname'         => 'John',
            'lastname'          => 'Doe',
            'version'           => \PluginFlyvemdmAgent::MINIMUM_ANDROID_VERSION . '.0',
            'type'              => 'android',
            'inventory'         => CommonTestCase::AgentXmlInventory($serial),
         ]
      );
      $this->boolean($agent->isNewItem())
         ->isFalse(json_encode($_SESSION['MESSAGE_AFTER_REDIRECT'], JSON_PRETTY_PRINT));

      // Create a fleet
      $fleet = $this->createFleet([
         'entities_id' => $_SESSION['glpiactive_entity'],
         'name'        => __FUNCTION__
      ]);

      // Move the agent to the fleet
      $this->boolean($agent->update([
         'id'                        => $agent->getID(),
         'plugin_flyvemdm_fleets_id' => $fleet->getID(),
      ]))->isTrue();

      // Test apply policy
      $policy = new \PluginFlyvemdmPolicy();
      $policy->getFromDBByCrit(['symbol' => 'storageEncryption']);
      $this->boolean($policy->isNewItem())->isFalse("Could not find the test policy");
      $fleetFk = \PluginFlyvemdmFleet::getForeignKeyField();
      $policyFk = \PluginFlyvemdmPolicy::getForeignKeyField();
      $task = $this->newTestedInstance();
      $taskId = $task->add([
         $fleetFk  => $fleet->getID(),
         $policyFk => $policy->getID(),
         'value'   => '0',
      ]);
      $this->boolean($task->isNewItem())
         ->isFalse(json_encode($_SESSION['MESSAGE_AFTER_REDIRECT'], JSON_PRETTY_PRINT));

      // Check a task status is created for the agent
      $taskStatus = new \PluginFlyvemdmTaskstatus();
      $taskFk = $task::getForeignKeyField();
      $rows = $taskStatus->find("`$taskFk` = '$taskId'");
      $this->integer(count($rows))->isEqualTo(1);
      foreach ($rows as $row) {
         $this->string($row['status'])->isEqualTo('pending');
      }

      // Check a MQTT message is sent
      sleep(2);

      $log = new \PluginFlyvemdmMqttlog();
      $rows = $log->find('', '`date` DESC', '1');
      $row = array_pop($rows);
      $mqttLogId = $row['id'];

      $policyName = $policy->getField('symbol');

      // check the topic of the message
      $this->string($row['topic'])->isEqualTo($fleet->getTopic() . "/Policy/$policyName/Task/$taskId");

      // check the message
      $receivedMqttMessage = json_decode($row['message'], JSON_OBJECT_AS_ARRAY);
      $this->array($receivedMqttMessage)->hasKey($policyName);
      $this->variable($receivedMqttMessage[$policyName])->isEqualTo($task->getField('value') == '0' ? 'false' : 'true');
      $this->array($receivedMqttMessage)->hasKey('taskId');
      $this->integer($receivedMqttMessage['taskId'])->isEqualTo($task->getID());

      // Test apply a policy twice fails
      $task = $this->newTestedInstance();

      $task->add([
         $fleetFk  => $fleet->getID(),
         $policyFk => $policy->getID(),
         'value'   => '0',
      ]);
      $this->boolean($task->isNewItem())->isTrue();

      // Test purge task
      $task->delete([
         'id' => $taskId,
      ], 1);

      // Check a mqtt message is sent to remove the applied policy from MQTT
      $rows = $log->find("`id` > '$mqttLogId' AND `direction`='O'", '`date` DESC', '1');
      $this->array($rows)->size->isEqualTo(1);
      $row = array_pop($rows);
      // check the topic of the message
      $this->string($row['topic'])->isEqualTo($fleet->getTopic() . "/Policy/$policyName/Task/$taskId");
      // check the message
      $this->string($row['message'])->isEqualTo('');

      // Check task statuses are deleted
      $rows = $taskStatus->find("`$taskFk` = '$taskId'");
      $this->integer(count($rows))->isEqualTo(0);

      // Test tassk status is created when an agent joins a fleet having policies
      // Create a 2nd fleet
      $fleet2 = $this->createFleet([
         'entities_id' => $_SESSION['glpiactive_entity'],
         'name'        => __FUNCTION__
      ]);

      // Apply a policy
      $policy = new \PluginFlyvemdmPolicy();
      $policy->getFromDBByCrit(['symbol' => 'disableWifi']);
      $this->boolean($policy->isNewItem())->isFalse("Could not find the test policy");
      $fleetFk = \PluginFlyvemdmFleet::getForeignKeyField();
      $policyFk = \PluginFlyvemdmPolicy::getForeignKeyField();
      $task2 = $this->newTestedInstance();
      $taskId2 = $task->add([
         $fleetFk  => $fleet2->getID(),
         $policyFk => $policy->getID(),
         'value'   => '0',
      ]);
      $this->boolean($task->isNewItem())
         ->isFalse(json_encode($_SESSION['MESSAGE_AFTER_REDIRECT'], JSON_PRETTY_PRINT));

      // Join the 2nd fleet
      $this->boolean($agent->update([
         'id'                        => $agent->getID(),
         'plugin_flyvemdm_fleets_id' => $fleet2->getID(),
      ]))->isTrue();

      // Check a task status is created for the agent
      $taskStatus2 = new \PluginFlyvemdmTaskstatus();
      $taskFk = $task::getForeignKeyField();
      $rows = $taskStatus2->find("`$taskFk` = '$taskId2'");
      $this->integer(count($rows))->isEqualTo(1);
      foreach ($rows as $row) {
         $this->string($row['status'])->isEqualTo('pending');
      }

      // Create a 3rd fleet
      $fleet3 = $this->createFleet([
         'entities_id' => $_SESSION['glpiactive_entity'],
         'name'        => __CLASS__ . '::'. __FUNCTION__,
      ]);

      // Apply a policy
      $policy = new \PluginFlyvemdmPolicy();
      $policy->getFromDBByCrit(['symbol' => 'disableGps']);
      $this->boolean($policy->isNewItem())->isFalse("Could not find the test policy");
      $fleetFk = \PluginFlyvemdmFleet::getForeignKeyField();
      $policyFk = \PluginFlyvemdmPolicy::getForeignKeyField();
      $task3 = $this->newTestedInstance();
      $taskId3 = $task->add([
         $fleetFk  => $fleet3->getID(),
         $policyFk => $policy->getID(),
         'value'   => '0',
      ]);

      // Join the 3rd fleet
      $this->boolean($agent->update([
         'id'                        => $agent->getID(),
         'plugin_flyvemdm_fleets_id' => $fleet3->getID(),
      ]))->isTrue();

      // Check a task status is created for the agent
      $taskStatus3 = new \PluginFlyvemdmTaskstatus();
      $taskFk = $task::getForeignKeyField();
      $rows = $taskStatus3->find("`$taskFk` = '$taskId3'");
      $this->integer(count($rows))->isEqualTo(1);
      foreach ($rows as $row) {
         $this->string($row['status'])->isEqualTo('pending');
      }

      // Check the old task status is canceled
      $rows = $taskStatus->find("`$taskFk` = '$taskId2'");
      $this->integer(count($rows))->isEqualTo(1);
      foreach ($rows as $row) {
         $this->string($row['status'])->isEqualTo('canceled');
      }
   }
}
