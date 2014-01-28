desired_order
1   	1
2   	2
3   	3
4   	4
5   	5
A  	7.1
A  	7.2
B  	6.1
B  	6.2
8   	8       
9  	10
9  	11
9  	9
9  	12
13  	13
14  	14
15  	15
16  	16
desired_order = c(1,2,3,4,5,"A","A","B","B",10,11,11,11,11,15,16,17)

shuffleBlock = function(desired_order){
    block_pos = which(tolower(str_sub(desired_order,1,1)) %in% letters) ## find blocks
    blocks = unique(desired_order[block_pos]) ## unique letters
    real_order = 1:length(desired_order) ## determine the order they were in the item table

    block_order = runif(length(blocks), head(block_pos,1), (head(block_pos,1)+1) ) ## get a uniform dist of random numbers from the first block position. this solution ignores multiple blocks.
    for(i in 1:length(blocks)) { ## loop through blocks
    	desired_order[ desired_order == blocks[i] ] = block_order[i] ## replace the block letter with its assigned number.
    }

    desired_order = as.numeric(desired_order)
    desired_order[- block_pos ] = desired_order[- block_pos ] + runif(length(desired_order[- block_pos ]),0.01,0.99) ### now shuffle all elements which have an equal number

    order(desired_order,real_order)
}