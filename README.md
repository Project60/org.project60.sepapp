# CiviSEPA Payment Processors

This CiviCRM extension contains two payment processors for the [CiviSEPA extension](https://github.com/Project60/org.project60.sepa).

They were originally part of CiviSEPA, but they seem to be more volatile and need more tweaking depending on your CiviCRM version and your payment processor setup.

In order to facilitate faster development cycles, these payment processors can now be developed and versioned independently from CiviSEPA.

The module currently provides two SEPA payment processors:
* ``SDD`` is the original payment processor implementation
* ``SDDNG`` is the 'next generation' processor, that was created to support membership and event payments.

However, both of them still might or might not work depending on your setup. Please test well before using productively.

## Get involved

This extension is maintained by civiservice.de and collaboratively developed with the CiviCRM community.
Whether you contribute code, provide testing, documentation, translations, or offer financial support to fund new features and improvements, your help is welcome.
