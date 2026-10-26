# History — finish a ticket where it is visible, not just where it builds

The incident history, measurements and worked examples behind [`closing-a-ticket.md`](../rules/closing-a-ticket.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`closing-a-ticket.md`](../rules/closing-a-ticket.md); this file is read when a rule is questioned, not on every turn.

## Overview

**Why this is a standing order.** The failure is never loud. A change that ships with four criteria left unchecked looks identical to one that met them; the ticket simply sits there implying work remains, or gets closed implying work happened. A card left in `Todo` while its branch merges makes the board a lie, and the board is what iteration planning reads. Residue that never gets a ticket is indistinguishable from residue nobody noticed. None of these break a build, which is exactly why they need a rule.
