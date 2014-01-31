#' Generate a order for elements that is shuffled in blocks
#'
#' The resulting order preserves the original order, but shuffles (a)
#' equal numeric entries (11, 11, 11) among each other and (b) shuffles
#' equal letter entries ("A", "A", "B", "B") in blocks so that the order
#' within the letter is preserved, but the letters are randomly ordered.
#'
#' @param desired_order alphanumeric vector consisting of natural numbers and letters
#' @export
#' @examples
#' desired_order = c(1,2,3,4,5,"A","A","B","B",10,11,11,11,11,15,16,17)
#' shuffleWithBlocks(desired_order)

shuffleWithBlocks = function(desired_order){
    block_pos = which(tolower(stringr::str_sub(desired_order,1,1)) %in% letters) ## find blocks
    blocks = unique(desired_order[block_pos]) ## unique letters
    real_order = 1:length(desired_order) ## determine the order they were in the item table

    block_order = runif(length(blocks), head(block_pos,1), (head(block_pos,1)+1) ) ## get a uniform dist of random numbers from the first block position. this solution ignores multiple blocks.
    
    resulting_order = desired_order
    for(i in 1:length(blocks)) { ## loop through blocks
    	resulting_order[ resulting_order == blocks[i] ] = block_order[i] ## replace the block letter with its assigned number.
    }

    resulting_order = as.numeric(resulting_order)
    resulting_order[- block_pos ] = resulting_order[- block_pos ] + runif(length(resulting_order[- block_pos ]),0.01,0.99) ### now shuffle all elements which have an equal number

    order(resulting_order,real_order)
}