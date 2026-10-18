<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Exceptions;

use LogicException;

/**
 * The workflow definition references unknown states, repeats names or misses approvers.
 */
class InvalidWorkflowDefinition extends LogicException {}
