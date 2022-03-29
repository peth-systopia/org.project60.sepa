<?php
/*-------------------------------------------------------+
| Project 60 - SEPA direct debit - PHPUnit tests         |
| Copyright (C) 2022 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

use CRM_Sepa_ExtensionUtil as E;

/**
 * Bug reproduction tests.
 *
 * The test numbers refer to issues on GitHub (https://github.com/Project60/org.project60.sepa/issues)
 *
 * @group headless
 */
class CRM_Sepa_BugReproductionTest extends CRM_Sepa_TestBase
{

  /**
   * Verify that but #629 is fixed:
   *  "each time recurring contributions are updated via the dashboard new contributions are created for recurring
   *   mandates even if there is a contribution for the current period.
   *
   * Presumably this goes back to a bad payment instrument id, set for example by a faulty payment processor
   *
   * @return void
   *
   * @see https://github.com/Project60/org.project60.sepa/issues/629
   */
  public function testBug629()
  {
    $this->setSepaConfiguration('exclude_weekends', '0');
    $this->setCreditorConfiguration('batching.RCUR.grace', 5);
    $this->setCreditorConfiguration('batching.RCUR.horizon', 33);
    $this->setCreditorConfiguration('batching.RCUR.notice', 2);
    $this->setCreditorConfiguration('batching.FRST.notice', 2);

    // create a recurring mandate
    $mandateDate = 'now - 45 days';
    $mandate = $this->createMandate(['type' => self::MANDATE_TYPE_RCUR], $mandateDate);

    // now: mess up the recurring contribution in a way that likely caused #629:
    // 1. give the recurring contribution the FRST contribution type
    $recurring_contribution = $this->civicrm_api('ContributionRecur', 'getsingle', [
      'id' => $mandate['entity_id'],
      'version' => 3]);
    $pi_mapping_reversed = array_flip(CRM_Sepa_Logic_PaymentInstruments::getFrst2RcurMapping($mandate['creditor_id']));
    $wrong_payment_instrument_id = $pi_mapping_reversed[$recurring_contribution['payment_instrument_id']];

    // make sure the recurring contribution of this mandate has the wrong PI
    $this->civicrm_api('ContributionRecur', 'create', [
      'id' => $mandate['entity_id'],
      'payment_instrument_id' => $wrong_payment_instrument_id,
      'contribution_status_id' => self::CONTRIBUTION_STATUS_IN_PROGRESS,
    ]);

    // create the FIRST group
    $this->executeBatching(self::MANDATE_TYPE_FRST, 'now - 45 days');
    $first_contribution = $this->getLatestContributionForMandate($mandate);
    $this->assertEquals(
              $pi_mapping_reversed[$recurring_contribution['payment_instrument_id']],
              $first_contribution['payment_instrument_id'],
              "The false payment instrument wasn't adopted. This might be correct if future checks are in place - in this case this test needs to be adapted.");
    $this->assertSame(
      self::RECURRING_CONTRIBUTION_STATUS_PENDING,
      $first_contribution['contribution_status_id'],
      "Contribution should be in status 'Pending'");

    // close the first group
    $transactionGroup = $this->getTransactionGroupForContribution($first_contribution);
    $this->closeTransactionGroup($transactionGroup['id']);
    $first_contribution = $this->getLatestContributionForMandate($mandate);
    $first_contribution_id = $first_contribution['id'];
    $this->assertEquals(
      $pi_mapping_reversed[$recurring_contribution['payment_instrument_id']],
      $first_contribution['payment_instrument_id'],
      "The false payment instrument wasn't adopted. This might be correct if future checks are in place - in this case this test needs to be adapted.");
    $this->assertEquals(self::RECURRING_CONTRIBUTION_STATUS_IN_PROGRESS, $first_contribution['contribution_status_id'],
                      "Contribution should be in status 'In Progress'");

    // make sure the recurring contribution of this mandate STILL has the wrong PI
    $this->civicrm_api('ContributionRecur', 'create', [
      'id' => $mandate['entity_id'],
      'payment_instrument_id' => $wrong_payment_instrument_id,
      'contribution_status_id' => self::CONTRIBUTION_STATUS_IN_PROGRESS,
    ]);
    // make sure the contribution of this mandate STILL has the wrong PI
    $this->civicrm_api('Contribution', 'create', [
      'id' => $first_contribution_id,
      'payment_instrument_id' => $wrong_payment_instrument_id
    ]);


    // generate and close SECOND collection
    $this->executeBatching(self::MANDATE_TYPE_FRST, 'now - 10 days');
    $this->executeBatching(self::MANDATE_TYPE_RCUR, 'now - 10 days');
    $second_contribution = $this->getLatestContributionForMandate($mandate);
    $second_contribution_id = $second_contribution['id'];
    $this->assertNotEquals($first_contribution_id, $second_contribution_id, "The algorithm should have generated a second contribution.");
    // make sure it has the wrong PI
    if ($second_contribution['payment_instrument_id'] != $wrong_payment_instrument_id) {
      $this->civicrm_api('Contribution', 'create', [
        'id' => $second_contribution_id,
        'payment_instrument_id' => $wrong_payment_instrument_id]);
    }
    $second_transactionGroup = $this->getTransactionGroupForContribution($second_contribution);
    $this->closeTransactionGroup($second_transactionGroup['id']);
    $second_contribution = $this->getLatestContributionForMandate($mandate);
    $this->assertEquals(self::RECURRING_CONTRIBUTION_STATUS_IN_PROGRESS, $second_contribution['contribution_status_id'],
                        "Contribution should be in status 'In Progress'");

    // make sure the recurring contribution of this mandate STILL has the wrong PI
    $this->civicrm_api('ContributionRecur', 'create', [
      'id' => $mandate['entity_id'],
      'contribution_status_id' => self::CONTRIBUTION_STATUS_IN_PROGRESS,
      'payment_instrument_id' => $wrong_payment_instrument_id
    ]);
    // make sure the contribution of this mandate STILL has the wrong PI
    $this->civicrm_api('Contribution', 'create', [
      'id' => $second_contribution_id,
      'payment_instrument_id' => $wrong_payment_instrument_id
    ]);

    // generate and close THIRD collection
    $this->executeBatching(self::MANDATE_TYPE_FRST, 'now - 10 days');
    $this->executeBatching(self::MANDATE_TYPE_RCUR, 'now - 10 days');
    $third_contribution = $this->getLatestContributionForMandate($mandate);
    $third_contribution_id = $third_contribution['id'];
    $this->assertEquals($second_contribution_id, $third_contribution_id, "The algorithm should not have generated a second contribution for the same date.");
//    $this->assertEquals(
//      $wrong_payment_instrument_id,
//      $third_contribution['payment_instrument_id'],
//      "The false payment instrument wasn't adopted. This might be correct if future checks are in place - in this case this test needs to be adapted.");
    $third_transactionGroup = $this->getTransactionGroupForContribution($third_contribution);
    $this->closeTransactionGroup($third_transactionGroup['id']);
    $third_contribution = $this->getLatestContributionForMandate($mandate);
    $this->assertEquals(self::RECURRING_CONTRIBUTION_STATUS_IN_PROGRESS, $third_contribution['contribution_status_id'],
                        "Contribution should be in status 'In Progress'");

    // now run the collection again, and see if an extra collection for the same date is generated
    $this->executeBatching(self::MANDATE_TYPE_RCUR, 'now - 15 days');
    $third_contribution = $this->getLatestContributionForMandate($mandate);
    $third_contribution_id = $third_contribution['id'];
    $this->assertEquals($second_contribution['id'], $third_contribution['id'], "The algorithm should not have generated a second contribution for the same date.");
  }
}
