<?php

declare(strict_types=1);

/**
 * Unit tests for Gutenberg block detection logic in the ImportWordPress command.
 *
 * Tests the private methods that detect mixed Classic and Gutenberg block content
 * to ensure they correctly identify pure Gutenberg blocks vs mixed content
 * scenarios.
 *
 * `hasFreeformContentOutsideBlocks()` and `removeAllGutenbergBlocks()` are pure
 * string transforms that touch none of the command's injected collaborators, so
 * the SUT is built via reflection without its 19 constructor dependencies (and
 * without a booted container) — the tier is PURE.
 *
 * @command  php vendor/bin/pest tests/Unit/GutenbergBlockDetectionTest.php
 */

use UAMSWeb\WordPressImporter\Console\Commands\ImportWordPress;

/**
 * Build an ImportWordPress instance without invoking its constructor.
 *
 * The freeform-detection methods reference no instance state, so bypassing the
 * constructor avoids resolving all 19 collaborators through the container.
 */
function gutenbergBlockCommand(): ImportWordPress
{
    return (new ReflectionClass(ImportWordPress::class))->newInstanceWithoutConstructor();
}

/**
 * Invoke one of the command's private block-detection methods via reflection.
 */
function invokeGutenbergBlockMethod(
    ImportWordPress $command,
    string $method,
    string $content
): mixed {
    $reflection = new ReflectionClass($command);
    $reflectionMethod = $reflection->getMethod($method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invoke(
        $command,
        $content
    );
}

/**
 * Invoke `removeAllGutenbergBlocks()` and assert its string contract.
 *
 * Reflection widens the method's `: string` return to mixed; this asserts the
 * original assertIsString() contract and narrows the value for static analysis.
 */
function gutenbergStringResult(
    ImportWordPress $command,
    string $content
): string {
    $result = invokeGutenbergBlockMethod(
        $command,
        'removeAllGutenbergBlocks',
        $content
    );

    expect($result)->toBeString();

    if (! is_string($result)) {
        throw new RuntimeException('removeAllGutenbergBlocks did not return a string.');
    }

    return $result;
}

it('detects freeform content outside blocks', function (string $content): void {
    $command = gutenbergBlockCommand();

    $result = invokeGutenbergBlockMethod(
        $command,
        'hasFreeformContentOutsideBlocks',
        $content
    );

    // Should detect freeform content outside blocks.
    expect($result)->toBeTrue();
})->with([
    'content with freeform text before and after gutenberg blocks' => [
        'Some freeform text before blocks.

<!-- wp:acf/uams-content {
    "id": "block_620ff54d146ea",
    "name": "acf\/uams-content",
    "data": {
        "content_heading": "Carolyn Pry",
        "_content_heading": "field_content_heading_b"
    },
    "align": "full",
    "mode": "auto"
} /-->

More freeform text after blocks.',
    ],
    'content with html before gutenberg blocks' => [
        '<p>This is some HTML content before the blocks.</p>

<!-- wp:acf/uams-content {
    "id": "block_620ff54d146ea",
    "name": "acf\/uams-content"
} /-->',
    ],
    'content with html after gutenberg blocks' => [
        '<!-- wp:acf/uams-content {
    "id": "block_620ff54d146ea",
    "name": "acf\/uams-content"
} /-->

<div class="custom-content">
    <p>This is custom HTML after the blocks.</p>
</div>',
    ],
    'content with text between gutenberg blocks' => [
        '<!-- wp:acf/uams-content {
    "id": "block_620ff54d146ea",
    "name": "acf\/uams-content"
} /-->

Some text between blocks.

<!-- wp:acf/uams-content {
    "id": "block_620ff571146eb",
    "name": "acf\/uams-content"
} /-->',
    ],
    'content with complex html sections before and after blocks' => [
        '<div class="intro-section">
    <h2>Introduction</h2>
    <p>This is an introduction section with custom HTML.</p>
</div>

<!-- wp:acf/uams-content {
    "id": "block_620ff54d146ea",
    "name": "acf\/uams-content",
    "data": {
        "content_heading": "Main Content",
        "_content_heading": "field_content_heading_b"
    }
} /-->

<div class="outro-section">
    <p>This is a conclusion section.</p>
</div>',
    ],
    'content with only freeform text, no blocks' => [
        'This is just plain text content with no Gutenberg blocks at all.',
    ],
    'content with only html, no Gutenberg blocks' => [
        '<h2>Title</h2>
<p>This is a paragraph with <strong>bold text</strong> and <em>italic text</em>.</p>
<ul>
    <li>List item 1</li>
    <li>List item 2</li>
</ul>',
    ],
]);

it('does not detect freeform content in pure Gutenberg blocks', function (string $content): void {
    $command = gutenbergBlockCommand();

    $result = invokeGutenbergBlockMethod(
        $command,
        'hasFreeformContentOutsideBlocks',
        $content
    );

    // Should NOT detect freeform content outside blocks.
    expect($result)->toBeFalse();
})->with([
    'page with file block' => [
        '<!-- wp:file {"id":10214,"href":"https://supplychain.uams.edu/wp-content/uploads/sites/11/2024/09/Expense-Contract-Reference-Guide_09-2024.pdf","displayPreview":false,"previewHeight":1110} -->
<div class="wp-block-file"><a id="wp-block-file--media-d2c077e1-d942-46c3-87f5-664bbf6fa4bc" href="https://supplychain.uams.edu/wp-content/uploads/sites/11/2024/09/Expense-Contract-Reference-Guide_09-2024.pdf">Expense Contract Reference Guide_09-2024</a><a href="https://supplychain.uams.edu/wp-content/uploads/sites/11/2024/09/Expense-Contract-Reference-Guide_09-2024.pdf" class="wp-block-file__button wp-element-button" download aria-describedby="wp-block-file--media-d2c077e1-d942-46c3-87f5-664bbf6fa4bc">Download</a></div>
<!-- /wp:file -->',
    ],
    'supply chain service level agreement' => [
        '<!-- wp:paragraph -->
<p>Each year, the Procurement Services Department typically processes +115,000 purchase orders with a purchase order value in excess of $315 million dollars. The UAMS Procurement Services staff is bound to strict purchasing guidelines mandated by various agencies: Arkansas Department of Finance and Administration Procurement Laws and Rules, Travel and Accounting Regulations, Arkansas Ethics Laws, the University of Arkansas System Policies, as well as UAMS Policies and Procedures.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>In accordance with the UAMS Mission Statement to “Improve the health, healthcare and well-being of all Arkansans and of others in the region, nation and the world &nbsp;by: Education of current and future health professions and the public; Providing high quality, innovative, patient-and-family-centered, health care and also providing specialty expertise not routinely available in community settings; and Advancing knowledge in areas of human health and disease and translating and accelerating discoveries into health improvements” and extension of services to the State of Arkansas and beyond… to support the UAMS mission,</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>WE, in Procurement Services process requisitions in the following order of urgency:</p>
<!-- /wp:paragraph -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li>Patient Care (Surgery, Sterile Supply, Cath Lab, Interventional Radiology, ICU’s, NICU and other clinical, patient care and research areas)</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Engineering or Operations (HVAC, plumbing, electrical, etc.)</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Regular Requisitions (Contracts expiring, date sensitive contracts, etc.)</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Reimbursements (personal purchases, recruitment dinners, official functions, etc.)</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>*Please note that these time frames mentioned are approximate, and contingent on emergent or urgent actions that may be required.&nbsp; Urgent matters should be communicated to your assigned buyer via telephone or email, as soon as you enter the requisition, to notify them of your need for expedited processing.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><strong>Anticipated Timeline for Requisition Processing</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>NOTE: The timelines listed below are after the requisition has been released and has all of the required accurate documentation attached to the requisition.&nbsp; You can track workflow progress /release status by using transaction(s) ZMMAPPCHECK and/or ZREQS.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong>Regular Requisition – </strong>Anticipated turnaround time is <u>up to 3 business days</u>. Regular Requisition includes those with an established vendor with item(s) in Item Master, with Physician’s Preference letter, Personal Reimbursements, Check with Order (CWO), and P‐CARD Purchases ‐ memberships, licenses, books, etc.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>Regular Requisitions that includes vendor set-up, additional signature or review </strong>- Anticipated turnaround time is <u>up to</u> <u>7 business days</u> (or more depending on negotiation of terms and vendor response times). Examples may include a new or revised vendor set‐up, new vendor terms and conditions, new forms, credit application, those that are for a Sole Source or Exempt Purchase request ‐ (provided appropriate form is used and approved by all department parties).</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>Requisitions with Terms &amp; Conditions - </strong>can take <u>up to 10</u><u>‐</u><u>12 business days</u> or much longer (depending on negotiations with vendor’s and UAMS (legal department). Examples may include new or revised contract language, Business Associate Agreement (BAA), ECRI price analysis negotiations, the cost of the contract, as well as obtaining UAMS approvals and signatures after negotiations are completed.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>Thresholds </strong>– a requisition that exceeds the state’s mandated bid limits will require a Sole Source Form, Physician’s Preference letter, or Exempt Justification Form. If the Sole Source Form, Physician’s Preference letter, or Exempt Justification Form supplied is not approved, then state procedures for bidding must be followed. The only exception to this requirement for bidding is if the purchase is being made utilizing one of our GPO’s, specific good and services exempt from procurement laws, or goods and services available from an approved State Arkansas supplier contract.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>Here are the current bidding thresholds, per Arkansas state purchasing regulations:</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Notes and exceptions:<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Bid limits stated below may be <em>doubled</em> if you are obtaining goods or services from a verified minority provider. You must note “minority vendor” in your header text if you are utilizing this option.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Construction projects have different bid limits and require coordination with Campus Operations for bidding and specification.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Printing has different bidding requirements and limits.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>$20,000 or less: The order is considered a small order and does not require bidding. Attach the vendor quote or other valid back-up to your requisition.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>$20,000 to $75,000: Competitive Bid which requires 3 written quotes from valid companies that can provide the product or service. When this method is used please complete and attach the Quote Bid form (found on the “Forms and Instructions” area of this website) to your requisition along with copies of all bids submitted.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>In excess of $75, 0000: Formal Sealed Bid, which requires notice in the local newspaper, and a public opening in which the bid results are read. The process can take 4‐6 weeks. Alternatively, an RFP may be processed. The RFP bidding process allows various performance criteria to be included in the evaluation process – in addition to cost. This method is used, when applicable, to aid departments when vendor capabilities and past performance/history are measurable elements in addition to the cost factor. This process can take 2‐3 months and involves assistance in writing the specifications, committee formation, receipt and evaluation of proposals and recommendations for award. If Consultant in nature, legislative review may be required and would extend timeline.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p><strong>Effective August 24, 2017, UAMS is currently Exempt from All State of Arkansas Sales and Use Taxes.&nbsp;</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Revised 11/8/2018</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>&nbsp;</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>&nbsp;</p>
<!-- /wp:paragraph -->',
    ],
    'uams content blocks with one in a group block' => [
        '<!-- wp:acf/uams-content {"name":"acf/uams-content","data":{"content_heading":"Visiting Medical Student Elective Rotations","_content_heading":"field_content_heading_b","content_hide_heading":"1","_content_hide_heading":"field_content_hide_heading_b","content_content":"The Department of Neurosurgery invites visiting medical students to participate in visiting student rotations at the University of Arkansas for Medical Sciences (UAMS).\r\n\r\nOur four-week elective rotations offer students the opportunity to rotate with all faculty and specialty areas to see the breadth of Neurosurgery.","_content_content":"field_content_description_b","content_background_color":"bg-white","_content_background_color":"field_content_background_color_b","geo_valid":"false","_geo_valid":"field_content_geo_valid_b"},"align":"full","mode":"auto"} /-->

<!-- wp:group {"align":"full"} -->
<div class="wp-block-group alignfull"><!-- wp:acf/uams-content {"name":"acf/uams-content","data":{"content_heading":"How to Apply for a Visiting Student Rotation ","_content_heading":"field_content_heading_b","content_hide_heading":"0","_content_hide_heading":"field_content_hide_heading_b","content_content":"All medical students interested in participating in a UAMS Neurosurgery rotation must apply through \u003ca href=\u0022https://students-residents.aamc.org/students/vslo-students\u0022\u003eVisiting Student Learning Opportunities™\u003c/a\u003e (VSLO®).\r\n\r\nFor VSLO® Help or Inquiries:\r\n\r\n\u003ca href=\u0022https://students-residents.aamc.org/visiting-student-learning-opportunities/visiting-student-learning-opportunities-contact-form\u0022\u003eContact VSLO\u003c/a\u003e\r\n202-478-9878","_content_content":"field_content_description_b","content_background_color":"bg-gray","_content_background_color":"field_content_background_color_b","geo_valid":"false","_geo_valid":"field_content_geo_valid_b"},"align":"full","mode":"auto"} /--></div>
<!-- /wp:group -->',
    ],
    'mix of image and list blocks with empty paragraph block' => [
        '<!-- wp:image {"id":12054,"linkDestination":"custom"} -->
<figure class="wp-block-image"><a href="https://medicine.uams.edu/neurosurgery/wp-content/uploads/sites/30/2010/08/BradleyLucas.jpg"><img src="https://medicine.uams.edu/neurosurgery/wp-content/uploads/sites/30/2010/08/BradleyLucas-220x220.jpg" alt="" class="wp-image-12054"/></a></figure>
<!-- /wp:image -->

<!-- wp:paragraph -->
<p></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><span class="content"><span class="pageSubtitle">PGY- 7 &nbsp; Co-Chief Resident</span></span></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><span class="content"><span class="pageSubtitle"><strong>Medical School: &nbsp;</strong></span>University of Missouri School of Medicine<br></span></p>
<!-- /wp:paragraph -->',
    ],
    'paragraph blocks and list blocks' => [
        '<!-- wp:paragraph -->
<p>UAMS Neurosurgery Research Profiles and Publications on PubMed. PubMed is a service of the U.S. National Library of Medicine that includes over 16 million citations from MEDLINE and other life science journals for biomedical articles back to the 1950s. PubMed includes links to full text articles and other related resources.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://uams-triprofiles.uams.edu/profiles/display/127491">Gregory Albert, M.D.</a>, MPH</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><a href="https://uams-triprofiles.uams.edu/profiles/display/127651">J.D. Day, M.D.</a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><a href="https://uams-triprofiles.uams.edu/profiles/display/1151132">Noojan Kazemi, M.D.</a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>T.W. Morris, III, M.D.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><a href="https://uams-triprofiles.uams.edu/profiles/display/125877">Eylem Ocal, M.D.</a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><a href="https://uams-triprofiles.uams.edu/profiles/display/125970">Thomas G. Pait, M.D.</a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><a href="https://uams-triprofiles.uams.edu/profiles/display/5219522">Viktoras Palys, M.D. </a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><a href="https://uams-triprofiles.uams.edu/profiles/display/126825">Erika Petersen, M.D.</a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><a href="https://uams-triprofiles.uams.edu/profiles/display/4955383">Analiz Rodriguez, M.D., Ph.D.</a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Tomoko Tanaka, M.D.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>&nbsp;</p>
<!-- /wp:paragraph -->',
    ],
    'single acf block with complex json data and embedded html content' => [
        '<!-- wp:acf/uams-content {
    "id": "block_620ff54d146ea",
    "name": "acf\/uams-content",
    "data": {
        "content_heading": "Carolyn Pry",
        "_content_heading": "field_content_heading_b",
        "content_hide_heading": "0",
        "_content_hide_heading": "field_content_hide_heading_b",
        "content_content": "<a href=\"https:\/\/uamshealth.com\/news\/2018\/01\/03\/surgeons-team-up-to-remove-pituitary-gland-tumor\/\"><img class=\"wp-image-12465 img-fluid alignright\" src=\"http:\/\/medicine.uams.edu\/neurosurgery\/wp-content\/uploads\/sites\/30\/2018\/01\/group.jpg\" sizes=\"(max-width: 480px) 100vw, 480px\" srcset=\"http:\/\/medicine.uams.edu\/neurosurgery\/wp-content\/uploads\/sites\/30\/2018\/01\/group.jpg 480w, http:\/\/medicine.uams.edu\/neurosurgery\/wp-content\/uploads\/sites\/30\/2018\/01\/group-300x225.jpg 300w, http:\/\/medicine.uams.edu\/neurosurgery\/wp-content\/uploads\/sites\/30\/2018\/01\/group-160x120.jpg 160w, http:\/\/medicine.uams.edu\/neurosurgery\/wp-content\/uploads\/sites\/30\/2018\/01\/group-299x224.jpg 299w\" alt=\"Carolyn Pry with doctors\" width=\"480\" height=\"360\" \/><\/a>Carolyn Pry says members of her church congregation were surprised to see her in Sunday worship just days after she\'d had brain surgery.",
        "_content_content": "field_content_description_b",
        "content_background_color": "bg-white",
        "_content_background_color": "field_content_background_color_b",
        "geo_valid": "false",
        "_geo_valid": "field_content_geo_valid_b"
    },
    "align": "full",
    "mode": "auto"
} /-->',
    ],
    'multiple acf blocks in sequence' => [
        '<!-- wp:acf/uams-content {
    "id": "block_620ff54d146ea",
    "name": "acf\/uams-content",
    "data": {
        "content_heading": "Carolyn Pry",
        "_content_heading": "field_content_heading_b"
    },
    "align": "full",
    "mode": "auto"
} /-->

<!-- wp:acf/uams-content {
    "id": "block_620ff571146eb",
    "name": "acf\/uams-content",
    "data": {
        "content_heading": "Les Marlow",
        "_content_heading": "field_content_heading_b"
    },
    "align": "full",
    "mode": "auto"
} /-->',
    ],
    'acf blocks with embedded youtube videos in content' => [
        '<!-- wp:acf/uams-content {
    "id": "block_620d73c8e7d07",
    "name": "acf\/uams-content",
    "data": {
        "content_heading": "Residency Program Overview",
        "_content_heading": "field_content_heading_b",
        "content_hide_heading": "0",
        "_content_hide_heading": "field_content_hide_heading_b",
        "content_content": "<div class=\"embed-responsive embed-responsive-16by9 alignwide\"><iframe class=\"embed-responsive-item ui-droppable\" title=\"Neurosurgery Resident Testimonials\" src=\"https:\/\/www.youtube.com\/embed\/riCfrh7sM6E\" allowfullscreen=\"allowfullscreen\"><\/iframe><\/div>",
        "_content_content": "field_content_description_b",
        "content_background_color": "bg-white",
        "_content_background_color": "field_content_background_color_b",
        "geo_valid": "false",
        "_geo_valid": "field_content_geo_valid_b"
    },
    "align": "full",
    "mode": "auto"
} /-->

<!-- wp:acf/uams-content {
    "id": "block_620e616236c3e",
    "name": "acf\/uams-content",
    "data": {
        "content_heading": "What Would You Tell Your Intern Self?",
        "_content_heading": "field_content_heading_b",
        "content_hide_heading": "0",
        "_content_hide_heading": "field_content_hide_heading_b",
        "content_content": "<div class=\"embed-responsive embed-responsive-16by9 alignwide\"><iframe class=\"embed-responsive-item ui-droppable\" title=\"What Would You Tell Your Intern Self?\" src=\"https:\/\/www.youtube.com\/embed\/9lJZBlUjmHM\" allowfullscreen=\"allowfullscreen\"><\/iframe><\/div>",
        "_content_content": "field_content_description_b",
        "content_background_color": "bg-gray",
        "_content_background_color": "field_content_background_color_b",
        "geo_valid": "false",
        "_geo_valid": "field_content_geo_valid_b"
    },
    "align": "full",
    "mode": "auto"
} /-->',
    ],
    'standard gutenberg blocks with opening and closing comments' => [
        '<!-- wp:paragraph -->
<p>This is a standard Gutenberg paragraph block.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>This is a heading block</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul><li>List item 1</li><li>List item 2</li></ul>
<!-- /wp:list -->',
    ],
    'mixed standard gutenberg blocks and acf blocks' => [
        '<!-- wp:paragraph -->
<p>This is a standard paragraph.</p>
<!-- /wp:paragraph -->

<!-- wp:acf/uams-content {
    "id": "block_620ff54d146ea",
    "name": "acf\/uams-content"
} /-->

<!-- wp:heading -->
<h2>Another heading</h2>
<!-- /wp:heading -->',
    ],
    'empty content' => [
        '',
    ],
    'content with only whitespace' => [
        '   '."\n\t".'   ',
    ],
]);

it('removes all Gutenberg block types while preserving freeform content', function (): void {
    $command = gutenbergBlockCommand();

    // Self-closing ACF blocks are removed entirely.
    $contentWithAcfBlocks = '<!-- wp:acf/uams-content {
    "id": "block_620ff54d146ea",
    "name": "acf\/uams-content"
} /-->';
    expect(\trim(gutenbergStringResult($command, $contentWithAcfBlocks)))->toBe('');

    // Standard opening/closing blocks are removed entirely.
    $contentWithStandardBlocks = '<!-- wp:paragraph -->
<p>This is a paragraph.</p>
<!-- /wp:paragraph -->';
    expect(\trim(gutenbergStringResult($command, $contentWithStandardBlocks)))->toBe('');

    // Mixed content preserves non-block content while removing blocks.
    $mixedContent = 'Some text before.

<!-- wp:acf/uams-content {
    "id": "block_620ff54d146ea",
    "name": "acf\/uams-content"
} /-->

Some text after.';
    $mixedResult = gutenbergStringResult($command, $mixedContent);

    $expectedResult = 'Some text before.

Some text after.';

    // The regex may leave extra newlines, so normalize whitespace for comparison.
    $normalizedResult = \preg_replace('/\n\s*\n/', "\n\n", \trim($mixedResult));
    $normalizedExpected = \preg_replace('/\n\s*\n/', "\n\n", \trim($expectedResult));

    expect($normalizedResult)->toBe($normalizedExpected);
});
