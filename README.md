# CapacityAvailability

A small PHP library for calculating time windows with sufficient remaining capacity.

The goal is to answer practical questions such as: *Can a request reserve three units for 45 minutes, given overlapping reservations and capacity that changes through the day?*

This repository is being prepared for an initial reviewed release. Its initial implementation is under development in a separate branch.

The planned core is framework-independent; transactional persistence integration is optional. Calculating availability and committing a reservation are deliberately separate responsibilities.

**Status:** pre-release, no public API stability guarantee yet.
