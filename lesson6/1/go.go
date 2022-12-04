package aisd01_task3

import (
	"os"
	"fmt"
)

type DynArray[T any] struct {
	count    int
	capacity int
	array    []T
}

func (da *DynArray[T]) Init() {
	da.count = 0
	da.MakeArray(16)
}

func (da *DynArray[T]) MakeArray(sz int) {
	var arr = make([]T, sz)
	for i := 0; i < da.count; i++ {
		arr[i] = da.array[i]
	}
	da.capacity = sz
	da.array = arr
}

func (da *DynArray[T]) GetItem(index int) (T, error) {
	var result T

	if index < 0 || index > da.count {
		return result, fmt.Errorf("bad index '%d'", index)
	}
	return da.array[index], nil
}

func (da *DynArray[T]) Append(itm T) {
	if da.count == da.capacity {
		da.MakeArray(da.capacity * 2)
	}

	da.array[da.count] = itm
	da.count++
}

func (da *DynArray[T]) Insert(itm T, index int) error {
	if index < 0 || index > da.count {
		return fmt.Errorf("bad index '%d'", index)
	}

	if index == da.count {
		da.Append(itm)
		return nil
	}

	if da.count == da.capacity {
		da.MakeArray(2 * da.capacity)
	}

	var i = da.count
	for i > index {
		da.array[i] = da.array[i-1]
		i--
	}

	da.count++
	da.array[index] = itm

	return nil
}

func (da *DynArray[T]) Remove(index int) error {

	if index < 0 || index >= da.count {
		return fmt.Errorf("bad index '%d'", index)
	}

	for i := index; i <= da.count-1; i++ {
		da.array[i] = da.array[i+1]
	}
	da.count--

	var division float32 = float32(da.count) / float32(da.capacity)

	if division < 0.5 && da.capacity > 16 {
		var newCapacity = 10 * da.capacity / 15
		if newCapacity > 16 {
			da.capacity = newCapacity
		} else {
			da.capacity = 16
		}
	}

	return nil
}