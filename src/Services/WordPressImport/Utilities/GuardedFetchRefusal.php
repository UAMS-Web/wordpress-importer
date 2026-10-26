<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Why {@see GuardedRedirectWalk} abandoned a redirect chain.
 *
 * The walk reports a structured reason rather than a rendered sentence because
 * its three call sites render refusals differently and must keep doing so: the
 * asset download path writes console comments an operator reads mid-import,
 * link resolution writes application-log warnings carrying entry context, and
 * the existence check reports nothing at all. Handing each of them the case and
 * letting it phrase its own message keeps that difference where it belongs, and
 * keeps a wording change in one path from silently rewriting another's output.
 */
enum GuardedFetchRefusal
{
    /**
     * A hop's host resolved to a non-public address, or its scheme was not
     * http(s). The refused hop may be the start URL or any redirect target.
     */
    case NonPublicTarget;

    /**
     * The chain's whole-run time budget ran out before this hop was sent.
     */
    case BudgetExhausted;

    /**
     * The transport failed — a connection error, a timeout, a TLS failure.
     * Only reported by a walk that swallows transport failures; one that
     * propagates them never reaches this case.
     */
    case TransportFailure;

    /**
     * A `Location` header could not be parsed as a URI, so the next hop is
     * unknown. Guzzle's parser throws rather than reporting failure, so this is
     * the case that once aborted a whole import over one malformed redirect.
     */
    case UnparseableLocation;

    /**
     * The chain pointed back at a URL already visited during this walk.
     */
    case RedirectLoop;

    /**
     * The chain exceeded {@see OutboundUrlGuard::MAX_REDIRECT_HOPS} without
     * reaching a non-redirect response.
     */
    case TooManyRedirects;
}
