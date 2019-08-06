<?php

namespace bvb\cti;

/**
 * Force implementation of the getParentSearchModel() function
 */
interface CtiSearchModelInterface
{
    /**
     * Return an instance of the "parent" search model which will have the rules and basic search fucntionality
     * @return string
     */
    public function getParentSearchModelClass();
}
